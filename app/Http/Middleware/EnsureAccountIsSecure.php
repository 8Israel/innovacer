<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a logged-in user out of the application until their account is usable:
 * an inactive user is logged out, a temporary password must be changed first,
 * and two-step verification must be set up before anything else.
 */
class EnsureAccountIsSecure
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => __('Tu usuario está desactivado. Habla con el dueño.'),
            ]);
        }

        if ($user->must_change_password) {
            return $request->routeIs('password.change') ? $next($request) : redirect()->route('password.change');
        }

        if (! $user->hasTwoStepVerification() && ! $request->routeIs('security.edit', 'password.change')) {
            return redirect()->route('security.edit')->with('status', __('Activa la verificación en dos pasos para continuar.'));
        }

        return $next($request);
    }
}
