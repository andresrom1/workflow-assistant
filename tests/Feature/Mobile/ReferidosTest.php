<?php

use App\Models\MobileAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('requiere autenticación', function (): void {
    $this->getJson('/api/mobile/v1/referidos/link')
        ->assertStatus(401)
        ->assertJson(['code' => 'UNAUTHENTICATED']);
});

it('devuelve un stub con code/url/stub:true', function (): void {
    $a = MobileAccount::factory()->create();
    Sanctum::actingAs($a, ['*'], 'mobile');

    $this->getJson('/api/mobile/v1/referidos/link')
        ->assertOk()
        ->assertJson(['stub' => true])
        ->assertJsonStructure(['code', 'url']);
});
