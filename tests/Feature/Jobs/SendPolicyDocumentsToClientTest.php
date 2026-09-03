<?php

use App\Jobs\SendPolicyDocumentsToClient;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.doc001']]], 200),
    ]);
    config(['services.whatsapp.phone_number_id' => '123456789']);
});

it('sends every visible document with a phone number', function () {
    $customer = Customer::factory()->create(['phone' => '+5491112345678']);
    $conversation = Conversation::factory()->create([
        'ext_user_id' => 'user_abc123',
        'customer_id' => $customer->id,
    ]);
    $poliza = Poliza::factory()->create(['numero' => 'POL-999', 'company' => 'Sancor']);
    $documento = PolicyDocument::factory()->create([
        'poliza_id' => $poliza->id,
        'storage_url' => 'https://r2.example.com/poliza.pdf',
        'visible_to_client' => true,
    ]);
    PolicyDocument::factory()->hiddenFromClient()->create([
        'poliza_id' => $poliza->id,
        'storage_url' => 'https://r2.example.com/hidden.pdf',
    ]);

    (new SendPolicyDocumentsToClient($poliza->id, $conversation->id))->handle(app(WhatsAppOutboundService::class));

    Http::assertSentCount(1);
    Http::assertSent(function ($request) use ($documento) {
        $body = $request->data();
        $link = (string) ($body['document']['link'] ?? '');

        return ($body['to'] ?? null) === '5491112345678'
            && ($body['type'] ?? null) === 'document'
            // El link va FIRMADO y apunta al objeto por su path. No puede ser la URL
            // pública guardada en `storage_url`: el bucket es privado y esa no resuelve.
            && str_contains(rawurldecode($link), $documento->storage_path)
            && str_contains($link, 'X-Amz-Signature')
            && ! str_contains($link, 'r2.example.com')
            && ($body['document']['caption'] ?? null) === 'Póliza POL-999 — Sancor';
    });
});

it('does not resend once the policy documents were already sent (idempotent)', function () {
    $customer = Customer::factory()->create(['phone' => '+5491112345678']);
    $conversation = Conversation::factory()->create([
        'ext_user_id' => 'user_abc123',
        'customer_id' => $customer->id,
    ]);
    $poliza = Poliza::factory()->create();
    PolicyDocument::factory()->create([
        'poliza_id' => $poliza->id,
        'storage_url' => 'https://r2.example.com/poliza.pdf',
        'visible_to_client' => true,
    ]);

    $service = app(WhatsAppOutboundService::class);
    (new SendPolicyDocumentsToClient($poliza->id, $conversation->id))->handle($service);
    (new SendPolicyDocumentsToClient($poliza->id, $conversation->id))->handle($service);

    Http::assertSentCount(1);
});

it('does nothing and releases the idempotency key when there are no visible documents yet', function () {
    $conversation = Conversation::factory()->create();
    $poliza = Poliza::factory()->create();

    (new SendPolicyDocumentsToClient($poliza->id, $conversation->id))
        ->handle(app(WhatsAppOutboundService::class));

    Http::assertNothingSent();
    expect(Cache::has("policy_docs_sent_{$poliza->id}"))->toBeFalse();
});
