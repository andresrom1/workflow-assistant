<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    public function definition(): array
    {
        return [
            'session_uuid' => (string) Str::uuid(),
            'risk_snapshot_id' => RiskSnapshot::factory(),
            'conversation_id' => Conversation::factory(),
            'status' => 'processed',
            'expires_at' => Quote::endOfBusinessDay(),
        ];
    }

    /** Cotización cuyo precio ya no vale: la vista la muestra igual, con el CTA apagado. */
    public function vencida(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    /** Cotización lista para el checkout de una alternativa puntual. */
    public function enCheckout(int $alternativeId): static
    {
        return $this->state(fn (): array => [
            'status' => 'checkout_pending',
            'checkout_token' => Str::random(10),
            'checkout_alternative_id' => $alternativeId,
        ]);
    }

    /** Las 2 alternativas que el agente presentó por WhatsApp, con su justificación. */
    public function presentada(int $recomendadaId, int $segundaId): static
    {
        return $this->state(fn (): array => [
            'recommended_alternative_id' => $recomendadaId,
            'presented_alternative_ids' => [$recomendadaId, $segundaId],
            'presentation_reasons' => [
                (string) $recomendadaId => 'Es la franquicia más baja que conseguí a este precio.',
                (string) $segundaId => 'Sale menos por mes, con franquicia más alta.',
            ],
            'presented_at' => now(),
            'public_token' => Str::random(16),
        ]);
    }
}
