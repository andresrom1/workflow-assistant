<?php

use App\Jobs\NotifyClientEmissionFailed;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function quoteWithConversationForEmissionFailure(?Conversation $conversation = null): Quote
{
    $snapshot = RiskSnapshot::factory()->create();
    $conversation ??= Conversation::factory()->create();

    return Quote::create([
        'session_uuid' => (string) Str::uuid(),
        'risk_snapshot_id' => $snapshot->id,
        'conversation_id' => $conversation->id,
        'status' => 'emision_fallida',
        'checkout_token' => (string) Str::uuid(),
    ]);
}

beforeEach(function () {
    Bus::fake();
    config(['services.whatsapp.phone_number_id' => '123456789']);
});

it('dispatches SendWhatsAppMessage with the phone when the conversation has one', function () {
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => '5491112345678',
        'ext_user_id' => 'user_abc123',
    ]);
    $quote = quoteWithConversationForEmissionFailure($conversation);

    (new NotifyClientEmissionFailed($quote->id))->handle();

    Bus::assertDispatched(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job) {
        $phone = (fn () => $this->phone)->call($job);
        $bsuid = (fn () => $this->bsuid)->call($job);
        $text = (fn () => $this->text)->call($job);

        return $phone === '5491112345678'
            && $bsuid === 'user_abc123'
            && str_contains($text, 'inconveniente');
    });
});

it('dispatches SendWhatsAppMessage with only the bsuid when there is no phone', function () {
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => 'user_abc123',
        'ext_user_id' => 'user_abc123',
    ]);
    $quote = quoteWithConversationForEmissionFailure($conversation);

    (new NotifyClientEmissionFailed($quote->id))->handle();

    Bus::assertDispatched(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job) {
        $phone = (fn () => $this->phone)->call($job);
        $bsuid = (fn () => $this->bsuid)->call($job);

        return $phone === null && $bsuid === 'user_abc123';
    });
});

it('does not dispatch anything when the quote id does not exist', function () {
    (new NotifyClientEmissionFailed(999999))->handle();

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

it('is idempotent: a second handle() for the same quote does not send a second message', function () {
    $quote = quoteWithConversationForEmissionFailure();

    (new NotifyClientEmissionFailed($quote->id))->handle();
    (new NotifyClientEmissionFailed($quote->id))->handle();

    Bus::assertDispatchedTimes(SendWhatsAppMessage::class, 1);
});
