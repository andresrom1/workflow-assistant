<?php

namespace Tests\Feature\Adapters;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Models\Conversation;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\RiskSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppCheckoutUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_generates_absolute_url_with_app_url(): void
    {
        URL::forceRootUrl('https://mangobroker.com.ar');

        $conversation = Conversation::factory()->create();
        $snapshot = RiskSnapshot::factory()->create();

        $quote = Quote::create([
            'session_uuid' => (string) Str::uuid(),
            'risk_snapshot_id' => $snapshot->id,
            'conversation_id' => $conversation->id,
            'status' => 'processed',
        ]);

        $alternative = QuoteAlternative::create([
            'quote_id' => $quote->id,
            'aseguradora' => 'San Cristóbal',
            'normalized_grade' => 'C',
            'precio' => 66200,
            'moneda' => 'ARS',
        ]);

        $adapter = app(WhatsAppAdapter::class);
        $result = $adapter->checkout([
            'quoteId' => $quote->id,
            'quote_alternative_id' => $alternative->id,
        ], $conversation);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('checkout_url', $result);
        $this->assertStringStartsWith('https://mangobroker.com.ar/checkout/', $result['checkout_url']);
        $this->assertDoesNotMatchRegularExpression('#^/checkout/#', $result['checkout_url']);
    }
}
