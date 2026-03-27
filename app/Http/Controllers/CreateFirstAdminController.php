<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\DockerSetupState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class CreateFirstAdminController extends Controller
{
    /**
     * Show the form to create the first admin user. Only when User::count() === 0.
     */
    public function show(): Response|RedirectResponse
    {
        if (DockerSetupState::isDocker() && ! DockerSetupState::isSetupDone()) {
            return redirect('/docker-setup');
        }

        if (User::count() > 0) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/CreateFirstAdmin');
    }

    /**
     * Create the first admin user. Only when User::count() === 0. Reject with 403 otherwise.
     */
    public function store(Request $request): RedirectResponse
    {
        if (DockerSetupState::isDocker() && ! DockerSetupState::isSetupDone()) {
            return redirect('/docker-setup');
        }

        if (User::count() > 0) {
            abort(403, 'O primeiro administrador já foi criado.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_ADMIN,
            'tenant_id' => null,
        ]);

        $user->update(['tenant_id' => $user->id]);

        Auth::login($user);

        return redirect()->intended('/dashboard');
    }
}
