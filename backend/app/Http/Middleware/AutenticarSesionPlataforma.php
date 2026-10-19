<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AutenticarSesionPlataforma
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('plataforma');
        $usuario = $guard->user();
        $autenticada = $request->hasSession() && $request->session()->has($guard->getName());
        if ($usuario && (! $usuario->activo || ($autenticada && ! hash_equals($usuario->getAuthPassword(), (string) $request->session()->get('password_hash_plataforma'))))) {
            $guard->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(401, 'La sesión ya no es válida. Vuelve a iniciar sesión.');
        }

        return $next($request);
    }
}
