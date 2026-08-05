<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthenticateUserController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->safe()->only(['email', 'password']);
        $user = User::query()->where('email', $credentials['email'])->first();

        if (
            $user !== null
            && Hash::check($credentials['password'], $user->password)
            && $user->passwordHasExpired()
        ) {
            return back()
                ->withErrors([
                    'email' => 'Tu contraseña ha vencido. Solicita a un administrador que actualice tu contraseña.',
                ])
                ->onlyInput('email');
        }

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        return back()
            ->withErrors(['email' => 'El correo o la contraseña son incorrectos.'])
            ->onlyInput('email');
    }
}
