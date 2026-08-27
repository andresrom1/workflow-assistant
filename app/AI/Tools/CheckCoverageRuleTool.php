<?php

namespace App\AI\Tools;

use App\AI\Concerns\HasRealReplay;
use App\Models\AgentPrompt;
use App\Models\CoverageDocument;
use App\Models\QuoteAlternative;
use App\Traits\ConditionalLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
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

CUANDO USAR: cada vez que el cliente pregunta "esto cubre X?", "incluye grua?", "me cubre si me pasa Y?", "que pasa si...?", o cualquier variante donde necesites confirmar si un evento especifico esta dentro de una cobertura. Tambien cuando tu propia informacion en memoria o en el payload (features_tags, glosario) no alcanza para responder con certeza.

REGLA ABSOLUTA: nunca respondas sobre coberturas de memoria. Si el cliente pregunta, llama esta tool. Si dudas, llama esta tool. El resultado de la tool ES la fuente de verdad.

COMO LLAMARLA:
- NO avises que vas a consultar. NO pidas permiso. NO digas "dejame verificar". Simplemente ejecuta la tool y responde con el resultado.
- Parametros obligatorios: evento (el evento exacto que menciono el cliente), cobertura (codigo A/B/C/C+/CM/D, o "no_definida"), aseguradora (nombre de la compania, o "no especificada"), quote_alternative_id (ID de la alternativa en contexto, o "0"), antiguedad_vehiculo (anios del vehiculo, o "desconocida").
- Mapeo de normalized_grade a codigo: liability->A, basic->B, third_party_complete->C, third_party_complete_plus->C, all_risk->D. Si no sabes cual aplica, usa "no_definida".

COMO RESPONDER AL CLIENTE tras la tool:

La tool devuelve uno de dos resultados, y se responden distinto.

1. RESPUESTA FUNDADA: directo, sin hedges. El resultado de la tool es lo que decis.
- MAL: "No te lo puedo confirmar de memoria, si queres te lo verifico..."
- MAL: "Queres que te lo verifique?"
- BIEN: "La grua no esta incluida en esa cobertura."
- BIEN: "Si, esa cobertura incluye grua hasta 100 km."

2. LA TOOL DICE QUE NO LO TIENE VERIFICADO: transmitilo tal cual, en tus palabras, y
   ofrece averiguarlo. NO lo completes, NO lo deduzcas del nombre del plan, NO uses lo que
   sepas del mercado. Es un resultado valido y frecuente: los manuales no cubren todo, y
   una respuesta inventada compromete a la agencia con algo que la compania no valido.
- MAL: inventar un numero, un plazo o un tope que la tool no devolvio.
- MAL: contestar igual "en general es asi".
- BIEN: "Ese dato puntual no lo tengo confirmado, dejame chequearlo y te digo."
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
        $plan = 'no identificado';

        if ($altId > 0) {
            $alt = QuoteAlternative::find($altId);

            if ($alt instanceof QuoteAlternative) {
                $instructions .= $this->productBlock($alt);
                $plan = $alt->titulo;
            }
        }

        // 3. Documentación de la compañía: entera si entra, RAG sólo como salida.
        $docs = CoverageDocument::activeForCompany($aseguradora);
        $instructions .= $this->documentationBlock($docs);

        $agent = new AnonymousAgent(
            instructions: $instructions,
            messages: [],
            tools: $this->needsSearchFallback($docs) ? [new SearchCompanyDocumentationTool] : [],
        );

        // 4. Structured prompt to Expert
        $prompt = 'Evento consultado: '.($all['evento'] ?? '')
            ."\nPlan cotizado: {$plan}"
            ."\nCobertura: ".($all['cobertura'] ?? 'no_definida')
            ."\nAseguradora: {$aseguradora}"
            ."\nCompany slug: ".Str::slug($aseguradora)
            .($antiguedad !== 'desconocida' ? "\nAntiguedad vehiculo: {$antiguedad} anios" : '');

        return $agent->prompt($prompt)->text;
    }

    /**
     * Techo de caracteres de documentación que se inyecta entera.
     *
     * Hoy la compañía más grande son 37.040 caracteres sin curar (Sancor) y la estimación
     * curada más alta son ~92.000 (San Cristóbal, sus 3 documentos). 120.000 ≈ 38k tokens,
     * holgado contra los 128k de contexto, y deja margen para que un manual crezca sin que
     * el turno se caiga. Por encima de esto se vuelve al RAG, que es peor pero acotado.
     */
    private const DOC_BUDGET_CHARS = 120_000;

    /**
     * ¿Hay que darle la tool de búsqueda al experto?
     *
     * Sólo cuando la documentación NO entra en contexto. Con documentos que entran, la
     * búsqueda es estrictamente peor: el experto ve el manual completo, así que puede
     * ubicar la columna de SU plan y —lo que el RAG no permite— determinar que un plan
     * NO figura. Con 4 fragmentos nunca ve lo que no recuperó.
     *
     * Y sin documentos tampoco tiene sentido: la búsqueda tampoco tiene dónde buscar.
     *
     * @param  Collection<int, CoverageDocument>  $docs
     */
    private function needsSearchFallback(Collection $docs): bool
    {
        return $docs->isNotEmpty() && $this->totalChars($docs) > self::DOC_BUDGET_CHARS;
    }

    /** @param  Collection<int, CoverageDocument>  $docs */
    private function totalChars(Collection $docs): int
    {
        return (int) $docs->sum(fn (CoverageDocument $d): int => mb_strlen((string) $d->extracted_content));
    }

    /**
     * Documentación de la compañía, entera, con la regla de lectura.
     *
     * Reemplaza a la búsqueda por similitud, que se midió y no sirve acá: sobre 7 consultas
     * cuyo dato SÍ estaba en la documentación, los dos aciertos quedaron MÁS LEJOS (0,3890 y
     * 0,4034 de distancia coseno) que todos los fallos (0,2608 a 0,3766). No existe umbral que
     * los separe — el fallo típico es de especificidad, no de tema: una pregunta de granizo
     * sobre un auto devolvía el cuadro de camiones de Triunfo, que también habla de granizo.
     *
     * @param  Collection<int, CoverageDocument>  $docs
     */
    private function documentationBlock(Collection $docs): string
    {
        $encabezado = "\n\n## DOCUMENTACION DE LA COMPANIA\n\n";

        if ($docs->isEmpty()) {
            return $encabezado
                ."NO HAY DOCUMENTACION CARGADA para esta compania.\n\n"
                .'No tenes con que responder topes, montos, cantidades de eventos, kilometros ni '
                .'condiciones por antiguedad. Decilo: no lo tenes verificado. NO lo deduzcas del '
                .'nombre del plan ni del conocimiento general del mercado.';
        }

        if ($this->needsSearchFallback($docs)) {
            return $encabezado
                .'La documentacion de esta compania no entra completa en contexto, asi que tenes '
                ."la tool `search_company_documentation` para buscar fragmentos.\n"
                .'CUIDADO: la busqueda devuelve lo mas parecido, NO necesariamente lo que responde. '
                .'Verifica que el fragmento hable del plan y del tipo de vehiculo que te preguntan '
                .'antes de usarlo.';
        }

        $cuerpo = $docs->map(fn (CoverageDocument $d): string => sprintf(
            "### %s — %s%s\n\n%s",
            $d->document_type,
            $d->original_filename,
            $d->version !== null && $d->version !== '' ? " (version {$d->version})" : '',
            (string) $d->extracted_content,
        ))->implode("\n\n---\n\n");

        return $encabezado
            ."Esto es TODA la documentacion cargada de la compania. No hay nada mas.\n\n"
            ."COMO LEERLA:\n"
            .'- Ubica la fila/columna del PLAN COTIZADO que figura en la consulta. Los cuadros '
            ."tienen una columna por plan y el nombre puede diferir del de la cotizacion.\n"
            .'- Si el plan cotizado NO figura en el cuadro, decilo: no tenes respaldo para ese '
            ."plan. NO uses la columna de al lado.\n"
            .'- Fijate en el segmento: si el cuadro es de camiones o acoplados y te preguntan por '
            ."un auto, NO aplica.\n"
            .'- Si el dato no esta en este texto, NO esta especificado. Decilo asi. No lo '
            ."completes con lo que sabes del mercado.\n\n"
            .$cuerpo;
    }

    /**
     * Bloque `DATOS DEL PRODUCTO` que se le inyecta al experto.
     *
     * Tiene dos formas, y la diferencia es la que evita afirmar sin dato: la negación por
     * ausencia sólo vale si la enumeración de coberturas VINO. Visred manda algunos covers
     * con `features` vacío — `Auto Max 15` y `Garage` de Sancor, 31 de 2002 alternativas en
     * producción. `Auto Max 15` se vende a $67.737, así que "no cubre nada" es falso; con la
     * regla vieja ("feature ausente = no cubierta") el agente lo afirmaba para cualquier
     * pregunta, porque con la lista vacía TODA feature está ausente.
     */
    private function productBlock(QuoteAlternative $alt): string
    {
        $encabezado = "\n\n## DATOS DEL PRODUCTO (fuente primaria)\n\n"
            ."Aseguradora: {$alt->aseguradora}\n"
            ."Plan: {$alt->titulo} — {$alt->descripcion}\n";

        $features = $alt->features_tags ?? [];

        if ($features === []) {
            return $encabezado
                ."\nENUMERACION DE COBERTURAS: NO DISPONIBLE para este plan.\n\n"
                .'El proveedor no envio la lista de coberturas de este producto. NO es que el plan '
                ."no cubra nada: es que el dato falta.\n"
                .'PROHIBIDO negar por ausencia en este caso — no digas que una cobertura no esta '
                ."incluida, porque no tenes con que saberlo.\n"
                .'Solo podes afirmar lo que encuentres en la documentacion de la compania.';
        }

        return $encabezado
            ."Nivel: {$alt->normalized_grade}\n"
            .'Features incluidas: '.implode(', ', $features)."\n"
            ."Detalle:\n"
            .collect($alt->full_details ?? [])
                ->map(fn (mixed $v, string $k): string => "- {$k}: {$v}")->implode("\n")
            ."\n\nEsta enumeracion esta COMPLETA: es la lista de riesgos que cubre el plan. "
            .'Una cobertura que no figura aca, no esta incluida.';
    }
}
