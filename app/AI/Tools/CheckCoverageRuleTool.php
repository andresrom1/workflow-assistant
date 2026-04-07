<?php

namespace App\AI\Tools;

use App\Models\AgentPrompt;
use App\Traits\ConditionalLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CheckCoverageRuleTool implements Tool
{
    use ConditionalLogger;

    public function description(): string
    {
        return 'Consulta las reglas de la póliza para saber si un evento o siniestro específico está cubierto. '
            .'Usar cuando el cliente pregunta "¿esto cubre X?" o "¿qué pasa si me pasa Y?". '
            .'Nunca respondas sobre coberturas de memoria; siempre usá esta herramienta.';
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
                ->enum(['A', 'B', 'C', 'D', 'no_definida'])
                ->description('Tipo de cobertura en contexto de la conversación. Usar "no_definida" si aún no se estableció.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->logToolCall($request->all());

        $instructions = Cache::rememberForever(
            'agent_prompt:coverage_check',
            fn () => AgentPrompt::activeFor('coverage_check')?->content
                ?? (string) file_get_contents(resource_path('prompts/agents/AGENT_COVERAGE_CHECK.md'))
        );

        $agent = new AnonymousAgent(
            instructions: $instructions,
            messages: [],
            tools: [],
        );

        $all = $request->all();
        $cobertura = $all['cobertura'] ?? 'no_definida';

        $prompt = 'Evento: '.($all['evento'] ?? '')
            ."\nCobertura en contexto: ".($cobertura === 'no_definida' ? 'no definida' : $cobertura);

        return $agent->prompt($prompt)->text;
    }
}
