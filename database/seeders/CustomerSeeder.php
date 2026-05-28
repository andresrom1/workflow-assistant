<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

/**
 * Seed mínimo de tomadores (Customer) para probar la vinculación de identidad
 * de Fase 1. El seed completo de pólizas/PAS llega en Fase 2.
 *
 * El Customer cumple el rol de "tomador": es contra estos registros que se
 * matchea (email verificado por OAuth + DNI) cuando el usuario hace el claim.
 *
 * Para probar el flujo end-to-end con login real, definí en .env:
 *   MANGO_DEV_CUSTOMER_EMAIL=tu-cuenta-google@gmail.com
 *   MANGO_DEV_CUSTOMER_DNI=12345678
 * y se creará un tomador con TU email OAuth, así el match funciona.
 */
class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        // Tomadores fijos de prueba (las credenciales están documentadas en ROADMAP.md).
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

        // Tomador con el email OAuth real del dev, para test end-to-end del claim.
        if ($devEmail = env('MANGO_DEV_CUSTOMER_EMAIL')) {
            $fixtures[] = [
                'name' => 'Dev MANGO',
                'dni' => (string) env('MANGO_DEV_CUSTOMER_DNI', '99999999'),
                'email' => $devEmail,
                'phone' => '+5491100000000',
            ];
        }

        foreach ($fixtures as $data) {
            Customer::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'dni' => $data['dni'],
                    'phone' => $data['phone'],
                    'is_anonymous' => false,
                    'completed_at' => now(),
                ],
            );
        }
    }
}
