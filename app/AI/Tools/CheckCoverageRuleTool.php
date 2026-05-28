<?php

namespace App\AI\Tools;

use App\AI\Concerns\HasRealReplay;
use App\Models\AgentPrompt;
use App\Models\QuoteAlternative;
use App\Traits\ConditionalLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CheckCoverageRuleTool implements Tool
{
    use ConditionalLogger;
    use HasRealReplay;

    public function description(): string
    {
        return <<<'TXT'
Consulta las reglas de la poliza para saber si un evento o siniestro especifico esta cubierto.

CUANDO USAR: cada vez que el cliente pregunta "esto cubre X?", "incluye grua?", "me cubre si me pasa Y?", "que pasa si...?", o cualquier variante donde necesites confirmar si un evento especifico esta dentro de una cobertura. Tambien cuando tu propia informacion en memoria o en el payload (features_tags, full_details) no alcanza para responder con certeza.

REGLA ABSOLUTA: nunca respondas sobre coberturas de memoria. Si el cliente pregunta, llama esta tool. Si dudas, llama esta tool. El resultado de la tool ES la fuente de verdad.

COMO LLAMARLA:
- NO avises que vas a consultar. NO pidas permiso. NO digas "dejame verificar". Simplemente ejecuta la tool y responde con el resultado.
- Parametros obligatorios: evento (el evento exacto que menciono el cliente), cobertura (codigo A/B/C/C+/CM/D, o "no_definida"), aseguradora (nombre de la compania, o "no especificada"), quote_alternative_id (ID de la alternativa en contexto, o "0"), antiguedad_vehiculo (anios del vehiculo, o "desconocida").
- Mapeo de normalized_grade a codigo: liability->A, basic->B, third_party_complete->C, all_risk->D. Si no sabes cual aplica, usa "no_definida".

COMO RESPONDER AL CLIENTE tras la tool:
- Directo, sin hedges. El resultado de la tool es lo que decis.
- MAL: "No te lo puedo confirmar de memoria, si queres te lo verifico..."
- MAL: "Queres que te lo verifique?"
- MAL: "No puedo asegurarte que X tenga Y" (si tenes el resultado de la tool, el resultado ES la seguridad).
- BIEN: "La grua no esta incluida en esa cobertura."
- BIEN: "Si, esa cobertura incluye grua hasta 100 km."
TXT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'evento' => $schema->string()
                ->description('El evento o siniestro que pregunta el cliente. Ej: "robo de espejos", "granizo", "choque propio".')
                ->required(),
            'cobertura' => $schema->string()
                ->description('Codigo de cobertura: A, B, C, C+, CM, D, o "no_definida".')
                ->required(),
            'aseguradora' => $schema->string()
                ->description('Nombre de la aseguradora. Ej: "San Cristobal", "Triunfo". Usar "no especificada" si no se conoce.')
                ->required(),
            'quote_alternative_id' => $schema->string()
                ->description('ID de la alternativa de cotizacion en contexto. Usar "0" si no hay.')
                ->required(),
            'antiguedad_vehiculo' => $schema->string()
                ->description('Antiguedad del vehiculo en anios. Usar "desconocida" si no se sabe.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->logToolCall($request->all());

        $all = $request->all();
        $aseguradora = $all['aseguradora'] ?? 'no especificada';
        $altId = (int) ($all['quote_alternative_id'] ?? 0);
        $antiguedad = $all['antiguedad_vehiculo'] ?? 'desconocida';

        // 1. Load Expert Agent instructions (shared blocks + specific)
        $instructions = AgentPrompt::compose('coverage_check', ['shared_style', 'shared_grounding']);

        if ($instructions === '') {
            $instructions = (string) file_get_contents(resource_path('prompts/agents/CoverageCheckAgent.md'));
        }

        // 2. Inject product data (full_details) if available
        if ($altId > 0) {
            $alt = QuoteAlternative::find($altId);

            if ($alt instanceof QuoteAlternative) {
                $instructions .= "\n\n## DATOS DEL PRODUCTO (fuente primaria)\n\n"
                    ."Aseguradora: {$alt->aseguradora}\n"
                    ."Plan: {$alt->titulo} — {$alt->descripcion}\n"
                    ."Nivel: {$alt->normalized_grade}\n"
                    .'Features incluidas: '.implode(', ', $alt->features_tags ?? [])."\n"
                    ."Detalle:\n"
                    .collect($alt->full_details ?? [])
                        ->map(fn (mixed $v, string $k): string => "- {$k}: {$v}")->implode("\n");
            }
        }

        // 3. Expert Agent WITH RAG search tool
        $agent = new AnonymousAgent(
            instructions: $instructions,
            messages: [],
            tools: [new SearchCompanyDocumentationTool],
        );

        // 4. Structured prompt to Expert
        $prompt = 'Evento consultado: '.($all['evento'] ?? '')
            ."\nCobertura: ".($all['cobertura'] ?? 'no_definida')
            ."\nAseguradora: {$aseguradora}"
            ."\nCompany slug: ".Str::slug($aseguradora)
            .($antiguedad !== 'desconocida' ? "\nAntiguedad vehiculo: {$antiguedad} anios" : '');

        return $agent->prompt($prompt)->text;
    }
}
