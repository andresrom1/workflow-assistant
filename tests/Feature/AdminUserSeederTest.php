<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * La variable se guarda y se restaura, no se borra: los tests comparten proceso y varios
 * (PasSafetyNet, SeedPasAssignment) usan el seeder como fixture. Borrarla los rompía a todos
 * los que corrieran después.
 */
beforeEach(function (): void {
    $this->passwordOriginal = env('MANGO_ADMIN_PASSWORD');
});

afterEach(function (): void {
    if (is_string($this->passwordOriginal)) {
        putenv('MANGO_ADMIN_PASSWORD='.$this->passwordOriginal);
        $_ENV['MANGO_ADMIN_PASSWORD'] = $this->passwordOriginal;
        $_SERVER['MANGO_ADMIN_PASSWORD'] = $this->passwordOriginal;

        return;
    }

    putenv('MANGO_ADMIN_PASSWORD');
    unset($_ENV['MANGO_ADMIN_PASSWORD'], $_SERVER['MANGO_ADMIN_PASSWORD']);
});

/**
 * Había una contraseña fija en el seeder, publicada en el repo: cualquier base donde se
 * hubiera corrido `db:seed` quedaba con un admin de credencial conocida.
 */
it('no siembra el admin si no le dan una contraseña', function (): void {
    // phpunit.xml la fija para el resto de la suite; acá se prueba justamente su ausencia.
    putenv('MANGO_ADMIN_PASSWORD');
    unset($_ENV['MANGO_ADMIN_PASSWORD'], $_SERVER['MANGO_ADMIN_PASSWORD']);

    (new AdminUserSeeder)->run();

    expect(User::where('email', AdminUserSeeder::EMAIL)->exists())->toBeFalse();
});

it('siembra el admin con la contraseña provista', function (): void {
    // Los tres: `env()` resuelve por varios adaptadores y el de $_SERVER —que phpunit.xml
    // ya dejó cargado— le gana a putenv.
    putenv('MANGO_ADMIN_PASSWORD=una-contrasena-de-prueba');
    $_ENV['MANGO_ADMIN_PASSWORD'] = 'una-contrasena-de-prueba';
    $_SERVER['MANGO_ADMIN_PASSWORD'] = 'una-contrasena-de-prueba';

    (new AdminUserSeeder)->run();

    $admin = User::where('email', AdminUserSeeder::EMAIL)->first();

    expect($admin)->not->toBeNull()
        ->and(Hash::check('una-contrasena-de-prueba', $admin->password))->toBeTrue();
});
