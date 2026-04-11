<?php

use App\Services\Message\ContentClassifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('returns informational when LLM call fails', function () {
    Http::fake(['*' => Http::response(null, 500)]);

    $classifier = new ContentClassifier;

    expect($classifier->classify('El precio de tu cotización es $45.200 por mes.'))->toBe('informational');
});

it('returns informational when LLM returns unexpected value', function () {
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => 'unknown-value']]],
    ], 200)]);

    $classifier = new ContentClassifier;

    // Even if the model returns garbage, we fall back safely to informational.
    $result = $classifier->classify('Algún texto.');

    expect($result)->toBeIn(['conversational', 'informational']);
});
