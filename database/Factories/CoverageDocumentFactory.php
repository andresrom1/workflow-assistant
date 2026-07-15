<?php

namespace Database\Factories;

use App\Models\CoverageDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CoverageDocument>
 */
class CoverageDocumentFactory extends Factory
{
    protected $model = CoverageDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = fake()->company();

        return [
            'company_slug' => Str::slug($company),
            'company_name' => $company,
            'document_type' => fake()->randomElement(['insert', 'asistencia', 'manual', 'general']),
            'original_filename' => fake()->slug().'.pdf',
            'storage_path' => 'coverage-documents/'.fake()->uuid().'.pdf',
            'storage_disk' => 'r2',
            'mime_type' => 'application/pdf',
            'extracted_content' => null,
            'extraction_status' => fake()->randomElement(['pending', 'completed', 'failed']),
            'extraction_mode' => fake()->randomElement(['ai', 'manual']),
            'extraction_provider' => fake()->randomElement(['openai', 'anthropic', 'gemini']),
            'version' => fake()->optional()->numerify('v#.##'),
            'is_active' => true,
            'deprecated_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
            'deprecated_at' => now(),
        ]);
    }
}
