<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Un utente disattivato viene disconnesso subito, anche se aveva una sessione aperta. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && ! Auth::user()->active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['username' => 'Utente disattivato.']);
        }

        return $next($request);
    }
}
