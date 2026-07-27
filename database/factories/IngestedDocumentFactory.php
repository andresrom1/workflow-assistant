<?php

namespace Database\Factories;

use App\Enums\IngestaStatus;
use App\Enums\PolicyDocumentKind;
use App\Models\IngestedDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestedDocument>
 */
class IngestedDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $numero = (string) fake()->numerify('############');
        $patente = strtoupper(fake()->bothify('??###??'));

        return [
            'hash_sha256' => hash('sha256', fake()->unique()->uuid()),
            'kind' => PolicyDocumentKind::Poliza,
            'compania' => fake()->randomElement(['Sancor Seguros', 'Río Uruguay', 'San Cristóbal']),
            'numero_poliza' => $numero,
            'documento_numero' => (string) fake()->numerify('########'),
            'patente' => $patente,
            'status' => IngestaStatus::Pendiente,
            'original_filename' => fake()->slug().'.pdf',
            'storage_path' => 'ingesta/'.hash('sha256', fake()->uuid()).'.pdf',
            'storage_url' => fake()->url(),
            'detectado_en' => now(),
            'payload' => ['schema_version' => 1, 'documento' => ['numero_poliza' => $numero]],
            'campos_no_extraidos' => [],
            'poliza_id' => null,
            'policy_document_id' => null,
        ];
    }
}
