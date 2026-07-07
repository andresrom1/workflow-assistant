<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Services\SettingsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('conversations:follow-up-stalled')]
#[Description('Recontacta conversaciones con progreso parcial antes de que cierre la ventana de 24h de WhatsApp')]
class FollowUpStalledConversations extends Command
{
    private const TIMEZONE = 'America/Argentina/Buenos_Aires';

    /**
     * Un nudge fijo por etapa (no pasa por el LLM — el mensaje es genérico y
     * no necesita razonamiento; ver ROADMAP para el upgrade path a trigger-prompt).
     *
     * @var array<string, string>
     */
    private const NUDGES = [
        'vehicle_identified' => '¿Seguimos con la cotización de tu auto? Me faltaban algunos datos del vehículo.',
        'coverage_set' => '¿Seguimos con tu cotización? Me quedaba pendiente saber qué cobertura te interesa.',
        'quote_ready' => 'Tu cotización quedó en preparación. ¿Retomamos?',
        'checkout_done' => 'Tenés tus cotizaciones listas esperándote. ¿Querés que las repasemos?',
    ];

    public function handle(SettingsService $settings): int
    {
        if (! $settings->get('followup.enabled', true)) {
            return self::SUCCESS;
        }

        $now = Carbon::now(self::TIMEZONE);

        // Última corrida hábil antes del cierre de ventana: la próxima corrida es en
        // 1h, salvo pasadas las 20:00, cuya próxima corrida hábil es mañana a las 08:00.
        $nextRunAt = $now->hour >= 20
            ? $now->copy()->addDay()->setTime(8, 0)
            : $now->copy()->addHour();

        $candidates = Conversation::query()
            ->where('channel', 'whatsapp')
            ->whereRaw("coalesce((metadata->'ai_state'->>'checkout_done')::boolean, false) = false")
            ->whereRaw("metadata->>'followup_sent_at' IS NULL")
            ->whereRaw("coalesce((metadata->>'ai_paused')::boolean, false) = false")
            ->whereBetween('last_message_at', [$now->copy()->subHours(26), $now->copy()->subHour()])
            ->get();

        foreach ($candidates as $conversation) {
            $state = $conversation->aiState();

            if (! in_array(true, $state, true)) {
                continue; // sin ningún progreso — no molestar a un anónimo
            }

            $lastInboundAt = $conversation->messages()
                ->where('direction', 'inbound')
                ->latest('created_at')
                ->value('created_at');

            if (! $lastInboundAt) {
                continue;
            }

            $windowExpiresAt = $lastInboundAt->copy()->addHours(24);

            if ($windowExpiresAt->isPast() || $windowExpiresAt->gt($nextRunAt)) {
                // Ventana ya cerrada, o todavía hay una corrida más cercana al cierre.
                continue;
            }

            $this->sendNudge($conversation, $state);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, bool>  $state
     */
    private function sendNudge(Conversation $conversation, array $state): void
    {
        // Idempotencia: flag ANTES de despachar.
        $meta = $conversation->metadata ?? [];
        $meta['followup_sent_at'] = now()->toIso8601String();
        $conversation->update(['metadata' => $meta]);

        $firstFalseStage = collect(self::NUDGES)->keys()->first(fn (string $flag): bool => ! $state[$flag]);
        $text = self::NUDGES[$firstFalseStage] ?? self::NUDGES['checkout_done'];

        $bsuid = $conversation->ext_user_id;
        $phone = $conversation->external_conversation_id === $bsuid ? null : $conversation->external_conversation_id;

        SendWhatsAppMessage::dispatch(
            $phone,
            $bsuid,
            $text,
            config('services.whatsapp.phone_number_id'),
            $conversation->id,
            'followup'
        )->onQueue('whatsapp-outbound');
    }
}
