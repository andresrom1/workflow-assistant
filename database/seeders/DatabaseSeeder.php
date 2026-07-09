<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // AdminUserSeeder crea el user admin, que por jerarquía es también el
            // PAS por default asignado a los clientes (ver CustomerSeeder).
            AdminUserSeeder::class,
            AgentPromptSeeder::class,
            CustomerSeeder::class,
            MobileRiskSeeder::class,
        ]);
    }
}
