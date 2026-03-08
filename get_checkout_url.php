<?php

require_once __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$quote = \App\Models\Quote::where('status', 'checkout_pending')->latest()->first();

if ($quote) {
    $url = route('checkout.show', ['token' => $quote->checkout_token]);
    echo "URL de checkout: {$url}\n";
    echo "Token: {$quote->checkout_token}\n";
} else {
    echo "No hay quotes pendientes. Ejecuta: php artisan db:seed --class=CheckoutTestDataSeeder\n";
}