<?php

use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('no manda links cuando la cotizacion todavia no se presento ni abrio checkout', function () {
    $quote = Quote::factory()->create();

    $this->actingAs($this->user)
        ->get(route('quotes.show', $quote))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Quotes/Show')
            ->where('quote.presented_at', null)
            ->where('quote.public_url', null)
            ->where('quote.checkout_url', null)
        );
});

it('manda los links del comparador y del checkout y la fecha de presentacion en hora argentina', function () {
    $quote = Quote::factory()->create([
        'status' => 'checkout_pending',
        'public_token' => Str::random(16),
        'checkout_token' => Str::random(10),
        // En UTC a proposito: fijar la fecha en otra zona desfasa los casts de Eloquent al releerla.
        'presented_at' => Carbon::parse('2026-08-22 17:35:00', 'UTC'),
    ]);

    $this->actingAs($this->user)
        ->get(route('quotes.show', $quote))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('quote.public_url', route('cotizaciones.show', ['token' => $quote->public_token]))
            ->where('quote.checkout_url', route('checkout.show', ['token' => $quote->checkout_token]))
            ->where('quote.presented_at', '2026-08-22T14:35:00-03:00')
        );
});
