<?php

use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// NOTA: Carbon::setTestNow() con un Carbon en timezone no-UTC hace que el cast
// datetime de Eloquent parsee las columnas leídas de DB en esa timezone en vez
// de UTC (aunque la fila se haya guardado en UTC) — desfasando la instancia en
// 3hs. Por eso acá siempre se fija el testNow en UTC (con el equivalente horario
// en America/Argentina/Buenos_Aires anotado en comentario); el comando bajo test
// sigue haciendo la conversión real a esa timezone con Carbon::now($tz).

/**
 * Crea un Message inbound con created_at forzado (no es fillable en Message::$fillable).
 */
function stalledInboundMessage(Conversation $conversation, Carbon $createdAt): Message
{
    $message = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola, sigo esperando',
        'external_message_id' => 'wamid.stalled.'.uniqid(),
        'sender_phone' => $conversation->external_conversation_id,
    ]);
    $message->forceFill(['created_at' => $createdAt])->saveQuietly();

    return $message;
}

function stalledConversation(Carbon $lastMessageAt, array $aiState = []): Conversation
{
    return Conversation::factory()->create([
        'channel' => 'whatsapp',
        'external_conversation_id' => '549111'.random_int(1000000, 9999999),
        'last_message_at' => $lastMessageAt,
        'metadata' => ['ai_state' => array_merge([
            'customer_identified' => true,
            'vehicle_identified' => true,
            'coverage_set' => false,
            'quote_ready' => false,
            'checkout_done' => false,
        ], $aiState)],
    ]);
}

it('sends a nudge and flags the conversation when the window closes at the next run', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    Carbon::setTestNow(Carbon::parse('2026-07-06 18:00:00', 'UTC')); // 15:00 en America/Argentina/Buenos_Aires

    $conversation = stalledConversation(now()->subHours(23));
    stalledInboundMessage($conversation, now()->subHours(23));

    $this->artisan('conversations:follow-up-stalled')->assertSuccessful();

    Bus::assertDispatched(SendWhatsAppMessage::class, fn ($job) => true);

    expect($conversation->fresh()->metadata)->toHaveKey('followup_sent_at');

    Carbon::setTestNow();
});

it('does not send twice for the same conversation', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    Carbon::setTestNow(Carbon::parse('2026-07-06 18:00:00', 'UTC')); // 15:00 en America/Argentina/Buenos_Aires

    $conversation = stalledConversation(now()->subHours(23));
    stalledInboundMessage($conversation, now()->subHours(23));

    $this->artisan('conversations:follow-up-stalled');
    Bus::assertDispatchedTimes(SendWhatsAppMessage::class, 1);

    $this->artisan('conversations:follow-up-stalled');
    Bus::assertDispatchedTimes(SendWhatsAppMessage::class, 1);

    Carbon::setTestNow();
});

it('does not send when the window closes later than the next scheduled run', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    Carbon::setTestNow(Carbon::parse('2026-07-06 18:00:00', 'UTC')); // 15:00 en America/Argentina/Buenos_Aires

    // Última inbound hace 10h → la ventana cierra en 14h, mucho más lejos que la próxima corrida (1h).
    $conversation = stalledConversation(now()->subHours(10));
    stalledInboundMessage($conversation, now()->subHours(10));

    $this->artisan('conversations:follow-up-stalled');

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
    expect($conversation->fresh()->metadata)->not->toHaveKey('followup_sent_at');

    Carbon::setTestNow();
});

it('sends at the 20:00 run when the window closes overnight before the next run at 08:00', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    Carbon::setTestNow(Carbon::parse('2026-07-06 23:00:00', 'UTC')); // 20:00 en America/Argentina/Buenos_Aires

    // Última inbound hace 17h (03:00 de hoy) → la ventana cierra a las 03:00 de mañana,
    // antes de que llegue la próxima corrida hábil (08:00 de mañana).
    $conversation = stalledConversation(now()->subHours(17));
    stalledInboundMessage($conversation, now()->subHours(17));

    $this->artisan('conversations:follow-up-stalled');

    Bus::assertDispatched(SendWhatsAppMessage::class, fn ($job) => true);

    Carbon::setTestNow();
});

it('does not send once the 24h window is already closed', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    Carbon::setTestNow(Carbon::parse('2026-07-06 18:00:00', 'UTC')); // 15:00 en America/Argentina/Buenos_Aires

    $conversation = stalledConversation(now()->subHours(25));
    stalledInboundMessage($conversation, now()->subHours(25));

    $this->artisan('conversations:follow-up-stalled');

    Bus::assertNotDispatched(SendWhatsAppMessage::class);

    Carbon::setTestNow();
});

it('does not send when checkout is already done', function () {
    Bus::fake([SendWhatsAppMessage::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-06 18:00:00', 'UTC')); // 15:00 en America/Argentina/Buenos_Aires

    $conversation = stalledConversation(now()->subHours(23), ['checkout_done' => true]);
    stalledInboundMessage($conversation, now()->subHours(23));

    $this->artisan('conversations:follow-up-stalled');

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
    Carbon::setTestNow();
});

it('does not send when the ai is paused', function () {
    Bus::fake([SendWhatsAppMessage::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-06 18:00:00', 'UTC')); // 15:00 en America/Argentina/Buenos_Aires

    $conversation = Conversation::factory()->create([
        'channel' => 'whatsapp',
        'external_conversation_id' => '5491199900001',
        'last_message_at' => now()->subHours(23),
        'metadata' => [
            'ai_state' => ['customer_identified' => true, 'vehicle_identified' => true, 'coverage_set' => false, 'quote_ready' => false, 'checkout_done' => false],
            'ai_paused' => true,
        ],
    ]);
    stalledInboundMessage($conversation, now()->subHours(23));

    $this->artisan('conversations:follow-up-stalled');

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
    Carbon::setTestNow();
});

it('does not send for an anonymous conversation with no progress', function () {
    Bus::fake([SendWhatsAppMessage::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-06 18:00:00', 'UTC')); // 15:00 en America/Argentina/Buenos_Aires

    $conversation = stalledConversation(now()->subHours(23), [
        'customer_identified' => false,
        'vehicle_identified' => false,
    ]);
    stalledInboundMessage($conversation, now()->subHours(23));

    $this->artisan('conversations:follow-up-stalled');

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
    Carbon::setTestNow();
});

it('respects the followup.enabled setting', function () {
    Bus::fake([SendWhatsAppMessage::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-06 18:00:00', 'UTC')); // 15:00 en America/Argentina/Buenos_Aires

    DB::table('system_settings')
        ->where('key', 'followup.enabled')
        ->update(['value' => '0']);
    Cache::forget(SettingsService::CACHE_KEY);

    $conversation = stalledConversation(now()->subHours(23));
    stalledInboundMessage($conversation, now()->subHours(23));

    $this->artisan('conversations:follow-up-stalled');

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
    Carbon::setTestNow();
});

it('picks the nudge text matching the first false stage', function () {
    Bus::fake([SendWhatsAppMessage::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-06 18:00:00', 'UTC')); // 15:00 en America/Argentina/Buenos_Aires

    $conversation = stalledConversation(now()->subHours(23), [
        'coverage_set' => true,
        'quote_ready' => false,
    ]);
    stalledInboundMessage($conversation, now()->subHours(23));

    $this->artisan('conversations:follow-up-stalled');

    Bus::assertDispatched(SendWhatsAppMessage::class, function ($job) {
        $ref = new ReflectionClass($job);
        $text = tap($ref->getProperty('text'), fn ($p) => $p->setAccessible(true))->getValue($job);

        return str_contains($text, 'cotización quedó en preparación');
    });

    Carbon::setTestNow();
});
