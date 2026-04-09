<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@pasmobile.com'],
            [
                'name' => 'Administrador',
                'password' => Hash::make('changeme-2026'),
                'role' => UserRole::Admin,
            ]
        );
    }
}
