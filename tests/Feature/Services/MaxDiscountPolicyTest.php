<?php

use App\Services\MaxDiscountPolicy;

$catalog = [
    ['ref' => '0', 'percent' => 0.0],
    ['ref' => 'P', 'percent' => 10.0],
    ['ref' => '5', 'percent' => 15.0],
    ['ref' => 'O', 'percent' => 30.0],
];

it('elige el mayor descuento dentro del tope', function () use ($catalog) {
    expect((new MaxDiscountPolicy)->choose($catalog, 20.0))->toBe(['ref' => '5', 'percent' => 15.0]);
});

it('nunca supera el tope (descarta los del catálogo por encima)', function () use ($catalog) {
    // Tope 10 → ignora 15% y 30%, elige 10%.
    expect((new MaxDiscountPolicy)->choose($catalog, 10.0))->toBe(['ref' => 'P', 'percent' => 10.0]);
});

it('tope 0 → elige la opción sin bonificar (válida, satisface discount_id requerido)', function () use ($catalog) {
    expect((new MaxDiscountPolicy)->choose($catalog, 0.0))->toBe(['ref' => '0', 'percent' => 0.0]);
});

it('devuelve null cuando no hay descuentos', function () {
    expect((new MaxDiscountPolicy)->choose([], 50.0))->toBeNull();
});
