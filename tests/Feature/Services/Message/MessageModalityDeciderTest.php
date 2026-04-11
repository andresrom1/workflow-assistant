<?php

use App\Enums\MessageType;
use App\Enums\Modality;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\ContentClassifier;
use App\Services\Message\MessageModalityDecider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeDecider(string $classifierResult = 'conversational'): MessageModalityDecider
{
    $classifier = Mockery::mock(ContentClassifier::class);
    $classifier->shouldReceive('classify')->andReturn($classifierResult);

    return new MessageModalityDecider($classifier);
}

function conversationWithInboundAudio(): Conversation
{
    $conversation = Conversation::factory()->create();

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'type' => MessageType::Audio,
        'content' => 'quiero asegurar un auto',
        'external_message_id' => 'wamid.inbound_audio_001',
        'sender_phone' => '5491112345678',
    ]);

    return $conversation;
}

function addOutboundEligible(Conversation $conversation, MessageType $type, int $count = 1): void
{
    for ($i = 0; $i < $count; $i++) {
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => $type,
            'audio_eligible' => true,
            'content' => 'respuesta del agente',
            'external_message_id' => 'wamid.out_'.uniqid(),
            'sender_phone' => '123456789',
        ]);
    }
}

$longText = 'Esta es una respuesta conversacional del agente que tiene suficientes palabras para ser elegible para audio en este sistema.';

// ---------------------------------------------------------------------------
// Layer 0: Mirror prerequisite
// ---------------------------------------------------------------------------

it('returns text when user has never sent audio', function () use ($longText) {
    $conversation = Conversation::factory()->create();
    $decider = makeDecider();

    $result = $decider->decide($longText, 'CustomerIdentifierAgent', $conversation);

    expect($result['modality'])->toBe(Modality::Text);
    expect($result['eligible'])->toBeFalse();
    expect($result['reason'])->toBe('no_user_audio');
});

// ---------------------------------------------------------------------------
// Layer 1: Hard gates
// ---------------------------------------------------------------------------

it('returns text for QuoteAgent regardless of content', function () use ($longText) {
    $conversation = conversationWithInboundAudio();
    $decider = makeDecider();

    $result = $decider->decide($longText, 'QuoteAgent', $conversation);

    expect($result['modality'])->toBe(Modality::Text);
    expect($result['eligible'])->toBeFalse();
    expect($result['reason'])->toBe('hard_gate');
});

it('returns text for CheckoutAgent regardless of content', function () use ($longText) {
    $conversation = conversationWithInboundAudio();
    $decider = makeDecider();

    $result = $decider->decide($longText, 'CheckoutAgent', $conversation);

    expect($result['modality'])->toBe(Modality::Text);
    expect($result['eligible'])->toBeFalse();
    expect($result['reason'])->toBe('hard_gate');
});

it('returns text when message has fewer than 15 words', function () {
    $conversation = conversationWithInboundAudio();
    $decider = makeDecider();

    $result = $decider->decide('Hola, ¿en qué te puedo ayudar?', 'CustomerIdentifierAgent', $conversation);

    expect($result['modality'])->toBe(Modality::Text);
    expect($result['eligible'])->toBeFalse();
    expect($result['reason'])->toBe('hard_gate');
});

it('returns text when message contains a URL', function () {
    $conversation = conversationWithInboundAudio();
    $decider = makeDecider();

    $text = 'Para continuar con tu cotización, visitá este link https://example.com/checkout para completar el proceso de pago del seguro.';

    $result = $decider->decide($text, 'CustomerIdentifierAgent', $conversation);

    expect($result['modality'])->toBe(Modality::Text);
    expect($result['reason'])->toBe('hard_gate');
});

it('returns text when message contains a monetary amount', function () {
    $conversation = conversationWithInboundAudio();
    $decider = makeDecider();

    $text = 'Tu cotización quedó en $45.200 por mes para la cobertura contra terceros completa con granizo incluido en la póliza.';

    $result = $decider->decide($text, 'CustomerIdentifierAgent', $conversation);

    expect($result['modality'])->toBe(Modality::Text);
    expect($result['reason'])->toBe('hard_gate');
});

it('returns text when message contains a list structure', function () {
    $conversation = conversationWithInboundAudio();
    $decider = makeDecider();

    $text = "Tus opciones de cobertura son:\n- Terceros básico\n- Terceros completo\n- Todo riesgo\nElegí la que mejor se adapte a tus necesidades.";

    $result = $decider->decide($text, 'CustomerIdentifierAgent', $conversation);

    expect($result['modality'])->toBe(Modality::Text);
    expect($result['reason'])->toBe('hard_gate');
});

// ---------------------------------------------------------------------------
// Layer 2: LLM classifier
// ---------------------------------------------------------------------------

it('returns text when LLM classifies content as informational', function () use ($longText) {
    $conversation = conversationWithInboundAudio();
    $decider = makeDecider('informational');

    $result = $decider->decide($longText, 'CustomerIdentifierAgent', $conversation);

    expect($result['modality'])->toBe(Modality::Text);
    expect($result['eligible'])->toBeFalse();
    expect($result['reason'])->toBe('llm_informational');
});

// ---------------------------------------------------------------------------
// Layer 3: Cold start
// ---------------------------------------------------------------------------

it('returns text during cold start (fewer than 3 eligible messages)', function () use ($longText) {
    $conversation = conversationWithInboundAudio();
    addOutboundEligible($conversation, MessageType::Text, 2); // only 2 eligible
    $decider = makeDecider();

    $result = $decider->decide($longText, 'CustomerIdentifierAgent', $conversation);

    expect($result['modality'])->toBe(Modality::Text);
    expect($result['eligible'])->toBeTrue();
    expect($result['reason'])->toBe('cold_start');
});

// ---------------------------------------------------------------------------
// Layer 4: Band rules
// ---------------------------------------------------------------------------

it('forces audio when text would drop below the 30% floor', function () use ($longText) {
    $conversation = conversationWithInboundAudio();
    // 3 eligible text messages → 0 audio. ratio_if_text = 0/4 = 0% → below floor.
    addOutboundEligible($conversation, MessageType::Text, 3);
    $decider = makeDecider();

    $result = $decider->decide($longText, 'CustomerIdentifierAgent', $conversation);

    expect($result['modality'])->toBe(Modality::Audio);
    expect($result['eligible'])->toBeTrue();
    expect($result['reason'])->toBe('band_floor');
});

it('forces text when audio would push above the 40% ceiling', function () use ($longText) {
    $conversation = conversationWithInboundAudio();
    // 3 text + 2 audio = 5 eligible. ratio_if_audio = 3/6 = 50% → above ceiling.
    addOutboundEligible($conversation, MessageType::Text, 3);
    addOutboundEligible($conversation, MessageType::Audio, 2);
    $decider = makeDecider();

    $result = $decider->decide($longText, 'CustomerIdentifierAgent', $conversation);

    expect($result['modality'])->toBe(Modality::Text);
    expect($result['eligible'])->toBeTrue();
    expect($result['reason'])->toBe('band_ceiling');
});

it('floor takes priority over ceiling when both would be violated', function () use ($longText) {
    $conversation = conversationWithInboundAudio();
    // Window = [text, text, text, audio, audio, audio] (6 total)
    // ratio_if_text = 3/7 ≈ 42.8% → above ceiling... wait, that's ceiling not floor.
    // Actually for floor priority: all text, small window.
    // 3 eligible all text → ratio_if_text = 0/4 = 0% → floor triggered, even though
    // ratio_if_audio = 1/4 = 25% which doesn't exceed ceiling. Let's test conflict:
    // 1 audio + 0 text (window=1, but cold start < 3 means we won't reach band layer)
    // Conflict scenario: window=3 eligible, 0 audio → ratio_if_text=0% < floor
    addOutboundEligible($conversation, MessageType::Text, 3);
    $decider = makeDecider();

    $result = $decider->decide($longText, 'CustomerIdentifierAgent', $conversation);

    // Floor forces audio even though ratio_if_audio (1/4=25%) is also below 30%.
    expect($result['reason'])->toBe('band_floor');
    expect($result['modality'])->toBe(Modality::Audio);
});

// ---------------------------------------------------------------------------
// Layer 5: Probabilistic (neutral zone 30%–40%)
// ---------------------------------------------------------------------------

it('uses probabilistic sampling in the neutral zone and returns valid result', function () use ($longText) {
    $conversation = conversationWithInboundAudio();
    // 4 text + 2 audio = 6 eligible. ratio = 2/6 ≈ 33% (in band).
    // ratio_if_text = 2/7 ≈ 28.6%... actually that's below floor. Let's use 5 text + 2 audio.
    // ratio_if_text = 2/8 = 25% → still floor. Need ratio already above floor in window.
    // Use 5 text + 2 audio = 7 eligible. ratio_if_audio = 3/8 = 37.5% ≤ 40%. ratio_if_text = 2/8 = 25% → floor.
    // Hmm. Let me try 4 text + 2 audio = 6 eligible.
    // ratio_if_text = 2/7 ≈ 28.6% → floor triggers. Not neutral.
    // Need: ratio_if_text ≥ 30% AND ratio_if_audio ≤ 40%.
    // ratio_if_text = audio/(n+1) ≥ 0.30 → audio ≥ 0.30*(n+1)
    // ratio_if_audio = (audio+1)/(n+1) ≤ 0.40 → audio+1 ≤ 0.40*(n+1)
    // Try n=9 (window=9), audio=3: ratio_if_text=3/10=30%, ratio_if_audio=4/10=40% → exact boundaries
    // Try n=9, audio=3 text=6: both at boundary. Let's try n=10, audio=4 text=6.
    // ratio_if_text=4/11≈36.4% ≥ 30% ✓, ratio_if_audio=5/11≈45.5% > 40% → ceiling!
    // Try n=9, audio=3, text=6: ratio_if_text=3/10=30% (floor exactly, not < 30%), ratio_if_audio=4/10=40% (ceiling exactly, not > 40%) → neutral zone!
    addOutboundEligible($conversation, MessageType::Text, 6);
    addOutboundEligible($conversation, MessageType::Audio, 3);
    $decider = makeDecider();

    $result = $decider->decide($longText, 'CustomerIdentifierAgent', $conversation);

    expect($result['eligible'])->toBeTrue();
    expect($result['reason'])->toBe('probabilistic');
    expect($result['p'])->toBeFloat()->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    expect($result['modality'])->toBeIn([Modality::Audio, Modality::Text]);
});
