<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Profile/Edit');
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $data = $request->only('name', 'email');

        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }

        $request->user()->update($data);

        return back()->with('success', 'Perfil actualizado.');
    }
}
