<?php

namespace Database\Factories;

use App\Enums\PolicyDocumentKind;
use App\Enums\PolicyDocumentSource;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PolicyDocument>
 */
class PolicyDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $kind = fake()->randomElement(PolicyDocumentKind::cases());

        return [
            'poliza_id' => Poliza::factory(),
            'kind' => $kind,
            'storage_path' => 'policy-documents/'.fake()->numberBetween(1, 999)."/{$kind->value}-".fake()->uuid().'.pdf',
            'storage_url' => fake()->url(),
            'original_filename' => null,
            'label' => null,
            'source' => PolicyDocumentSource::VisredEmission,
            'visible_to_client' => true,
            'captured_at' => now(),
        ];
    }

    /**
     * Carga manual del admin: trae nombre de archivo original y un label opcional.
     */
    public function adminUpload(): static
    {
        return $this->state(fn (): array => [
            'source' => PolicyDocumentSource::AdminUpload,
            'original_filename' => fake()->slug().'.pdf',
            'label' => fake()->optional()->sentence(3),
        ]);
    }

    public function hiddenFromClient(): static
    {
        return $this->state(fn (): array => ['visible_to_client' => false]);
    }
}
