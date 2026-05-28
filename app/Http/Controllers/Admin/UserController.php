<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Admin/Users/Create', [
            'roles' => array_map(fn (UserRole $role) => [
                'value' => $role->value,
                'label' => match ($role) {
                    UserRole::Admin => 'Administrador',
                    UserRole::User => 'Usuario',
                    UserRole::Pas => 'PAS',
                },
            ], UserRole::cases()),
            'users' => User::orderBy('name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ]),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'role' => $request->role,
        ]);

        return redirect()->route('admin.users.create')
            ->with('success', "Usuario {$user->name} creado exitosamente.");
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $newPassword = Str::password(12);

        $user->update(['password' => $newPassword]);

        return redirect()->route('admin.users.create')
            ->with('success', "Contraseña de {$user->name} reseteada. Nueva contraseña temporal: {$newPassword}");
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            abort(403, 'No podés eliminar tu propia cuenta.');
        }

        $user->delete();

        return redirect()->route('admin.users.create')
            ->with('success', "Usuario {$user->name} eliminado.");
    }
}
