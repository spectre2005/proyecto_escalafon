<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookie de sesión propia para api/plataforma (config/plataforma.php). Va antes del grupo «api», es decir, antes de que
 * Sanctum abra la sesión: así esa sesión se lee y se invalida con su cookie, y la institucional (otra cookie, path /)
 * no se toca. El token CSRF de esta sesión lo entrega GET auth/csrf (la cookie XSRF-TOKEN queda en este path y el
 * frontend no la lee).
 */
class SesionPlataforma
{
    public function __construct(private readonly SessionManager $sesiones) {}

    public function handle(Request $request, Closure $next): Response
    {
        $cookie = (string) config('plataforma.sesion.cookie');
        $antes = ['session.cookie' => config('session.cookie'), 'session.path' => config('session.path')];
        if ($cookie === '' || $cookie === $antes['session.cookie']) {
            throw new RuntimeException('La cookie de sesión de la plataforma debe ser distinta de la institucional (PLATAFORMA_SESSION_COOKIE).');
        }
        config(['session.cookie' => $cookie, 'session.path' => config('plataforma.sesion.path')]);
        // Si el almacén de sesión ya existía en este proceso, adopta el nombre nuevo (la cookie se lee con este nombre).
        $this->sesiones->driver()->setName($cookie);
        try {
            return $next($request);
        } finally {
            // La cookie ya va en la respuesta: el proceso vuelve a la sesión institucional (pruebas, Octane, colas).
            config($antes);
            $this->sesiones->driver()->setName($antes['session.cookie']);
        }
    }
}
