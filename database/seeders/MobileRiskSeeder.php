<?php

namespace Database\Seeders;

use App\Enums\AssetType;
use App\Enums\PolizaEstado;
use App\Models\Customer;
use App\Models\MobileAccount;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\SharedRisk;
use App\Services\PolicyChainResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Sembra Risks (autos) + Pólizas vigentes para los Customers seed.
 *
 * También sembra un SharedRisk: Tomás Iglesias comparte su auto con el email
 * configurado en MANGO_DEV_CUSTOMER_EMAIL (vos). Así, al loguearte en la app,
 * además de tus propias pólizas vas a ver el Honda de Tomás como "Cuenta
 * Compartida" en tu Home.
 *
 * Depende de: CustomerSeeder.
 */
class MobileRiskSeeder extends Seeder
{
    public function run(PolicyChainResolver $chain): void
    {
        $tomas = Customer::where('email', 'tomas.mango@example.com')->first();
        $lucia = Customer::where('email', 'lucia.mango@example.com')->first();
        $devCustomer = ($devEmail = env('MANGO_DEV_CUSTOMER_EMAIL'))
            ? Customer::where('email', $devEmail)->first()
            : null;

        // Risks fijos
        if ($tomas) {
            $tomasRisk = $this->seedVehicleRisk($chain, $tomas, [
                'label' => 'Honda Civic 2022',
                'patente' => 'AG 112 PK',
                'marca' => 'Honda',
                'modelo' => 'Civic',
                'version' => 'EXL 2.0 AT',
                'year' => 2022,
                'combustible' => 'nafta',
                'uso' => 'particular',
                'codigo_postal' => '1414',
            ]);

            $this->seedPoliza($tomasRisk, [
                'numero' => 'POL-2026-100023',
                'company' => 'La Caja Seguros',
                'coverage' => 'Todo riesgo C',
                'coverage_detail' => 'Daños totales + parciales + robo',
                'sum_asegurada' => 12_800_000,
                'cuota' => 67_320,
                'cuota_due' => now()->addDays(8)->toDateString(),
                'vigencia' => now()->addMonths(7)->toDateString(),
                'emitida_en' => now()->subMonths(5)->toDateString(),
            ]);

            // SharedRisk: Tomás invita al email de Andrés (env), aceptado.
            if ($devEmail) {
                $tomasMobile = MobileAccount::firstOrCreate(
                    ['email' => 'tomas.mango@example.com'],
                    [
                        'firebase_uid' => 'seed-fake-tomas-'.Str::random(12),
                        'name' => 'Tomás Iglesias',
                        'customer_id' => $tomas->id,
                        'email_verified_at' => now(),
                    ],
                );

                SharedRisk::firstOrCreate(
                    [
                        'risk_id' => $tomasRisk->id,
                        'shared_with_email' => $devEmail,
                    ],
                    [
                        'invited_by_mobile_account_id' => $tomasMobile->id,
                        'token' => Str::random(48),
                        'expires_at' => now()->addMonths(6),
                        'accepted_at' => now()->subDays(20),
                    ],
                );
            }
        }

        if ($lucia) {
            $luciaRisk = $this->seedVehicleRisk($chain, $lucia, [
                'label' => 'Volkswagen Gol Trend 2019',
                'patente' => 'AC 845 NK',
                'marca' => 'Volkswagen',
                'modelo' => 'Gol Trend',
                'version' => 'Comfortline 1.6',
                'year' => 2019,
                'combustible' => 'nafta',
                'uso' => 'particular',
                'codigo_postal' => '5000',
            ]);

            $this->seedPoliza($luciaRisk, [
                'numero' => 'POL-2026-100024',
                'company' => 'Sancor Seguros',
                'coverage' => 'Terceros completo',
                'coverage_detail' => 'Responsabilidad civil + robo total',
                'sum_asegurada' => 6_400_000,
                'cuota' => 28_900,
                'cuota_due' => now()->addDays(22)->toDateString(),
                'vigencia' => now()->addMonths(4)->toDateString(),
                'emitida_en' => now()->subMonths(8)->toDateString(),
            ]);
        }

        // Auto de Andrés (titular real)
        if ($devCustomer) {
            $devRisk = $this->seedVehicleRisk($chain, $devCustomer, [
                'label' => 'Toyota Corolla 2021',
                'patente' => 'AE 428 LM',
                'marca' => 'Toyota',
                'modelo' => 'Corolla',
                'version' => 'XEI 2.0 CVT',
                'year' => 2021,
                'combustible' => 'nafta',
                'uso' => 'particular',
                'codigo_postal' => '1426',
            ]);

            $this->seedPoliza($devRisk, [
                'numero' => 'POL-2026-100042',
                'company' => 'La Caja Seguros',
                'coverage' => 'Todo riesgo C',
                'coverage_detail' => 'Daños totales + parciales + robo',
                'sum_asegurada' => 14_200_000,
                'cuota' => 78_450,
                'cuota_due' => now()->addDays(7)->toDateString(),
                'vigencia' => now()->addMonths(6)->toDateString(),
                'emitida_en' => now()->subMonths(6)->toDateString(),
            ]);

            // Lado titular: el dev ya compartió su Corolla con alguien, para ver
            // "Personas con acceso" en dev sin invitar a mano. El MobileAccount
            // del dev se reconcilia por email al loguearse (fallback OAuth).
            if ($devEmail) {
                $devMobile = MobileAccount::firstOrCreate(
                    ['email' => $devEmail],
                    [
                        'firebase_uid' => 'seed-fake-dev-'.Str::random(12),
                        'name' => 'Andrés Romero',
                        'customer_id' => $devCustomer->id,
                        'email_verified_at' => now(),
                    ],
                );

                SharedRisk::firstOrCreate(
                    [
                        'risk_id' => $devRisk->id,
                        'shared_with_email' => 'sofia.mango@example.com',
                    ],
                    [
                        'name' => 'Sofía',
                        'invited_by_mobile_account_id' => $devMobile->id,
                        'token' => Str::random(48),
                        'expires_at' => now()->addMonths(6),
                    ],
                );
            }
        }
    }

    /**
     * Delega en {@see PolicyChainResolver} (mismo dedup que ingesta/reporte/emisión/alta
     * manual): idempotente por patente normalizada, no por `label` (el label ahora lo
     * deriva el resolver de marca+modelo+patente).
     *
     * @param  array<string, mixed>  $vehicle
     */
    private function seedVehicleRisk(PolicyChainResolver $chain, Customer $customer, array $vehicle): Risk
    {
        return $chain->resolveRisk($customer, AssetType::Vehicle, collect($vehicle)->except('label')->toArray());
    }

    /** @param  array<string, mixed>  $data */
    private function seedPoliza(Risk $risk, array $data): Poliza
    {
        return Poliza::updateOrCreate(
            [
                'risk_id' => $risk->id,
                'numero' => $data['numero'],
            ],
            [
                'estado' => PolizaEstado::Vigente,
                ...$data,
            ],
        );
    }
}
