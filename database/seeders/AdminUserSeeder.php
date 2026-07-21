<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuario admin del panel. Por jerarquía, este mismo user es también el PAS
 * (Productor Asesor de Seguros) asignado a los clientes: su perfil de productor
 * (matrícula, teléfono) vive en `metadata` y es lo que consume el mobile.
 *
 * Idempotente por diseño: si el user ya existía (p. ej. se logueó antes de
 * correr el seed), igual se le fuerza el `metadata` completo. Sin esto, el
 * mobile recibe un PAS sin teléfono y el aviso de siniestro crashea al parsear
 * la respuesta.
 *
 * Configurables vía .env para no fijar datos reales en el repo:
 *   MANGO_DEV_PAS_NAME="Andrés Romero"
 *   MANGO_DEV_PAS_MATRICULA=97072
 *   MANGO_DEV_PAS_PHONE=+5493516280778
 *   MANGO_DEV_PAS_AVATAR_URL=https://...
 */
class AdminUserSeeder extends Seeder
{
    /** Email canónico del operador (admin + PAS por default). Fuente de verdad para CustomerSeeder. */
    // opcion-de-configuracion: email del operador/PAS por default
    public const EMAIL = 'andresrom@gmail.com';

    public function run(): void
    {
        $admin = User::firstOrNew(['email' => self::EMAIL]);

        // opcion-de-configuracion: nombre del operador
        $admin->name = env('MANGO_DEV_PAS_NAME') ?: ($admin->name ?: 'Andrés Romero');
        $admin->role = UserRole::Admin;

        // Solo seteamos password al crear: no pisamos la de un admin ya logueado.
        if (! $admin->exists) {
            $admin->password = Hash::make('changeme-2026'); // opcion-de-configuracion: password inicial del admin sembrado
        }

        // Perfil de PAS. `?:` (no el default de env()) para que un env vacío
        // igual caiga al default: matrícula y teléfono deben quedar SIEMPRE
        // presentes, o el mobile no puede ofrecer los CTAs de contacto del PAS.
        // opcion-de-configuracion: perfil del PAS por default (candidatos a vista de perfil por-PAS)
        $admin->metadata = array_filter([
            'matricula' => env('MANGO_DEV_PAS_MATRICULA') ?: '97072',
            'phone' => env('MANGO_DEV_PAS_PHONE') ?: '+5493516280778',
            'avatar_url' => env('MANGO_DEV_PAS_AVATAR_URL') ?: null,
        ], fn ($v) => $v !== null);

        $admin->save();
    }
}
