<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * AuthenticateSession de Sanctum (cierra la sesión si cambió la contraseña, comparando la huella password_hash_web) y,
 * además, rechaza una sesión autenticada que no tiene huella. Sanctum la aceptaría y le guardaría la huella de la
 * contraseña de ese momento: una sesión iniciada antes de que AuthController guardara la huella al iniciar sesión
 * sobreviviría así a un restablecimiento. Desde entonces toda sesión nueva tiene huella, de modo que solo las antiguas
 * deben volver a iniciar sesión una vez. Solo mira la sesión del guard web: el panel de plataforma tiene su propio
 * guard y su propia cookie.
 */
class AutenticarSesion extends AuthenticateSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->auth->guard('web');
        if ($request->hasSession() && $guard instanceof SessionGuard
            && $request->session()->has($guard->getName()) && ! $request->session()->has('password_hash_web')) {
            $guard->logoutCurrentDevice();
            $request->session()->flush();

            throw new AuthenticationException('Unauthenticated.', ['web', 'sanctum']);
        }

        return parent::handle($request, $next);
    }
}
