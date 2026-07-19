<?php

namespace App\AI\Tools;

use App\Models\Conversation;
use App\Models\User;
use App\Traits\ConditionalLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Devuelve indicaciones básicas ante un siniestro + el contacto del PAS del
 * cliente, para que el agente se los transmita. NO notifica a nadie ni
 * despacha ningún mensaje — es puramente informativa.
 *
 * Prelación de PAS (2 tiers, ver App\Http\Controllers\Mobile\SiniestroController
 * para el equivalente de la app con un 3er tier de vehículo compartido que acá
 * no aplica): PAS propio del cliente → PAS por default de MANGO.
 */
class SiniestroGuidanceTool implements Tool
{
    use ConditionalLogger;

    public function __construct(
        private readonly Conversation $conversation,
    ) {}

    public function description(): string
    {
        return <<<'TXT'
Da indicaciones básicas ante un siniestro y el contacto del PAS para que el cliente lo llame.

CUANDO USAR — dos casos:
1. El cliente reporta un siniestro que YA OCURRIÓ: choque, robo, granizo, incendio, "tuve un accidente", "me chocaron", "me robaron el auto".
2. El cliente pregunta qué hacer o a quién contactar ante un siniestro, aunque sea HIPOTÉTICO: "¿qué hago en caso de siniestro?", "¿si me pasa algo con quién hablo?", "¿a quién llamo si choco?".

CUANDO NO USAR: si la pregunta es sobre qué cubre una cobertura ("¿esto cubre granizo?") — para eso está check_coverage_rule, no esta tool.
TXT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'situacion' => $schema->string()
                ->description('Breve descripción de lo que contó el cliente, textual. Vacío si es una pregunta hipotética.'),
        ];
    }

    public function handle(Request $request): string
    {
        $this->logToolCall($request->all());

        $pas = $this->resolvePas();
        $telefono = $pas?->pasPhone();

        $contact = ($pas !== null && $telefono !== null)
            ? ['nombre' => $pas->name, 'telefono' => $telefono]
            : null;

        return json_encode([
            'indicaciones' => [
                'No admitas responsabilidad frente al otro conductor ni firmes nada en el lugar.',
                'Fotografiá los daños, el lugar y los documentos del tercero (si lo hay).',
                'Si hay heridos, no muevas el vehículo y llamá a emergencias (911).',
                'Hacé la denuncia del siniestro a la aseguradora dentro de las 72 horas.',
                'Guardá toda la documentación del hecho (fotos, datos del tercero, denuncia policial si corresponde).',
            ],
            'pas' => $contact,
            'nota' => $contact === null
                ? 'No hay un contacto de PAS disponible ahora mismo: decile al cliente que un asesor se va a comunicar con él.'
                : null,
        ]);
    }

    private function resolvePas(): ?User
    {
        $customer = $this->conversation->customer;

        if ($customer?->pas !== null) {
            return $customer->pas;
        }

        $email = config('mango.default_pas_email');
        if (! is_string($email) || $email === '') {
            return null;
        }

        return User::pas()->where('email', $email)->first();
    }
}
