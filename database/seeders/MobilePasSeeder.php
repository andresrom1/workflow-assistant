<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * PAS (Productor Asesor de Seguros) para la app móvil.
 *
 * Se modela como User con role=pas; el perfil (matrícula, teléfono, avatar)
 * vive en users.metadata JSONB.
 *
 * Configurables vía .env para no commitear datos reales:
 *   MANGO_DEV_PAS_EMAIL=tu-email@gmail.com
 *   MANGO_DEV_PAS_NAME="Andrés Romero"
 *   MANGO_DEV_PAS_MATRICULA=88.241
 *   MANGO_DEV_PAS_PHONE=+5491156782341
 *   MANGO_DEV_PAS_AVATAR_URL=https://...
 *
 * Si no se setea MANGO_DEV_PAS_EMAIL, se crea un PAS placeholder
 * (pas@pasmobile.com) que sirve para tests pero no para login real.
 */
class MobilePasSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('MANGO_DEV_PAS_EMAIL', 'pas@pasmobile.com');

        User::firstOrCreate(
            ['email' => $email],
            [
                'name' => env('MANGO_DEV_PAS_NAME', 'Lucía Fernández'),
                'password' => Hash::make('changeme-2026'),
                'role' => UserRole::Pas,
                'metadata' => array_filter([
                    'matricula' => env('MANGO_DEV_PAS_MATRICULA', '88.241'),
                    'phone' => env('MANGO_DEV_PAS_PHONE', '+5491156782341'),
                    'avatar_url' => env('MANGO_DEV_PAS_AVATAR_URL'),
                ], fn ($v) => $v !== null),
            ],
        );
    }
}
