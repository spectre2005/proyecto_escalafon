<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La API responde en local y en pruebas; en cualquier otro entorno solo si se habilitó expresamente
 * (API_HABILITADA=true). En producción, además, el contenedor no arranca si la configuración no pasa
 * produccion:verificar (HTTPS, sin depuración, cookies seguras, claves).
 *
 * Con «crud» (CRUD genérico por tabla): fuera de local y pruebas es de solo lectura. Su escritura salta las reglas de
 * cada sección (anular en vez de borrar, auditoría, folios) y el frontend no la usa: todas sus escrituras pasan por
 * las rutas de /expedientes. Solo se abre con API_CRUD_GENERICO_ESCRITURA=true.
 */
class ApiHabilitada
{
    public function handle(Request $request, Closure $next, ?string $alcance = null): Response
    {
        $local = app()->environment(['local', 'testing']);
        abort_unless($local || config('app.api_habilitada'), 403, 'La API no está habilitada en este entorno.');
        abort_if($alcance === 'crud' && ! $local && ! $request->isMethodSafe() && ! config('app.crud_generico_escritura'), 403,
            'En este entorno, el acceso genérico por tabla es de solo lectura. Usa las pantallas de cada sección.');

        return $next($request);
    }
}
