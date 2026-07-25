<?php

use App\Jobs\NotifyClientCheckoutCompleted;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function quoteWithConversation(?Conversation $conversation = null): Quote
{
    $snapshot = RiskSnapshot::factory()->create();
    $conversation ??= Conversation::factory()->create();

    return Quote::create([
        'session_uuid' => (string) Str::uuid(),
        'risk_snapshot_id' => $snapshot->id,
        'conversation_id' => $conversation->id,
        'status' => 'checkout_submitted',
        'checkout_token' => (string) Str::uuid(),
    ]);
}

beforeEach(function () {
    Bus::fake();
    config(['services.whatsapp.phone_number_id' => '123456789']);
});

it('dispatches SendWhatsAppMessage with the phone when the conversation has one', function () {
    $customer = Customer::factory()->create(['phone' => '+5491112345678']);
    $conversation = Conversation::factory()->create([
        'ext_user_id' => 'user_abc123',
        'customer_id' => $customer->id,
    ]);
    $quote = quoteWithConversation($conversation);

    (new NotifyClientCheckoutCompleted($quote->id))->handle();

    Bus::assertDispatched(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job) {
        $phone = (fn () => $this->phone)->call($job);
        $bsuid = (fn () => $this->bsuid)->call($job);
        $text = (fn () => $this->text)->call($job);

        return $phone === '5491112345678'
            && $bsuid === 'user_abc123'
            && str_contains($text, 'Gracias por confiar en MANGO');
    });
});

it('no afirma que la póliza ya se emitió — la emisión todavía puede fallar', function () {
    $customer = Customer::factory()->create(['phone' => '+5491112345678']);
    $conversation = Conversation::factory()->create([
        'ext_user_id' => 'user_abc123',
        'customer_id' => $customer->id,
    ]);
    $quote = quoteWithConversation($conversation);

    (new NotifyClientCheckoutCompleted($quote->id))->handle();

    Bus::assertDispatched(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job) {
        $text = (fn () => $this->text)->call($job);

        // "ya está en proceso de emisión" / "tu póliza ya" daban por hecho un
        // resultado que EmitirPoliza todavía puede rechazar (ver EmitirPolizaFailureTest).
        return ! str_contains($text, 'ya está en proceso de emisión')
            && ! str_contains($text, 'tu póliza ya');
    });
});

it('dispatches SendWhatsAppMessage with only the bsuid when there is no phone', function () {
    $customer = Customer::factory()->create(['phone' => null]);
    $conversation = Conversation::factory()->create([
        'ext_user_id' => 'user_abc123',
        'customer_id' => $customer->id,
    ]);
    $quote = quoteWithConversation($conversation);

    (new NotifyClientCheckoutCompleted($quote->id))->handle();

    Bus::assertDispatched(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job) {
        $phone = (fn () => $this->phone)->call($job);
        $bsuid = (fn () => $this->bsuid)->call($job);

        return $phone === null && $bsuid === 'user_abc123';
    });
});

it('does not dispatch anything when the quote id does not exist', function () {
    (new NotifyClientCheckoutCompleted(999999))->handle();

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});
