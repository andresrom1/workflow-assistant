<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seed de tomadores (Customer) — fixtures fijos + 1 opcional con tu email
 * OAuth real para testear claim end-to-end.
 *
 * Cada Customer queda con `pas_id` apuntando al user admin, que por jerarquía
 * actúa como PAS (modelo "asesor dedicado": un PAS por cliente).
 *
 * Para probar el flujo end-to-end con login real, definí en .env:
 *   MANGO_DEV_CUSTOMER_EMAIL=tu-email@gmail.com  (el mismo que tu OAuth)
 *   MANGO_DEV_CUSTOMER_DNI=12345678               (tu DNI real)
 */
class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        // El PAS por default es el operador admin sembrado por AdminUserSeeder
        // (resuelto por email, no por `pas()->first()`, para no depender del orden
        // de PKs cuando hay varios users pas/admin).
        $pas = User::where('email', AdminUserSeeder::EMAIL)->first();
        $pasId = $pas?->id;

        $fixtures = [
            [
                'name' => 'Tomás Iglesias',
                'dni' => '30123456',
                'email' => 'tomas.mango@example.com',
                'phone' => '+5491134567890',
            ],
            [
                'name' => 'Lucía Méndez',
                'dni' => '27987654',
                'email' => 'lucia.mango@example.com',
                'phone' => '+5491156789012',
            ],
        ];

        if ($devEmail = env('MANGO_DEV_CUSTOMER_EMAIL')) {
            $fixtures[] = [
                'name' => env('MANGO_DEV_CUSTOMER_NAME', 'Dev MANGO'),
                'dni' => (string) env('MANGO_DEV_CUSTOMER_DNI', '99999999'),
                'email' => $devEmail,
                'phone' => env('MANGO_DEV_CUSTOMER_PHONE', '+5491100000000'),
            ];
        }

        foreach ($fixtures as $data) {
            Customer::updateOrCreate(
                ['email' => $data['email']],
                [
                    'pas_id' => $pasId,
                    'name' => $data['name'],
                    'dni' => $data['dni'],
                    'phone' => $data['phone'],
                    'is_anonymous' => false,
                    'completed_at' => now(),
                ],
            );
        }

        // Garantía: TODO customer queda con PAS asignado por default (incluye los
        // creados fuera de este seed, p. ej. por chat/checkout antes de sembrar).
        if ($pasId !== null) {
            Customer::whereNull('pas_id')->update(['pas_id' => $pasId]);
        }
    }
}
