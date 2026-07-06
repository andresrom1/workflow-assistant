<?php

namespace Database\Seeders;

use App\Models\BillingCompany;
use Illuminate\Database\Seeder;

/**
 * Padrón de compañías facturables. SCAFFOLD: los CUIT son placeholders — Andrés debe
 * reemplazarlos por los reales (incluye compañías ajenas a Visred como Cooperación, LPS).
 *
 * Idempotente por `razon_social` (updateOrCreate): correr de nuevo no duplica.
 */
class BillingCompanySeeder extends Seeder
{
    public function run(): void
    {
        $companies = [
            ['razon_social' => 'Cooperación Seguros', 'cuit' => '30000000001'],
            ['razon_social' => 'La Perseverancia Seguros', 'cuit' => '30000000002'],
            // TODO(Andrés): completar las 14 compañías con sus CUIT reales.
        ];

        foreach ($companies as $company) {
            BillingCompany::updateOrCreate(
                ['razon_social' => $company['razon_social']],
                ['cuit' => $company['cuit'], 'condicion_iva' => 'RI', 'activo' => true],
            );
        }
    }
}
