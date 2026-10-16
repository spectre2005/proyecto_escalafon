<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiDisponible
{
    public function handle(Request $request, Closure $next): Response
    {
        $local = app()->environment(['local', 'testing']);
        $produccion = app()->environment('production') && config('deployment.api_enabled')
            && ! config('app.debug') && config('session.secure')
            && in_array(config('permisos.fuente'), ['dual', 'database'], true)
            && config('antivirus.enabled') && config('antivirus.enforce');
        abort_unless($local || $produccion, 403, 'La API funcional no está habilitada en este entorno.');

        return $next($request);
    }
}
