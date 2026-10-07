<?php

namespace App\Http\Middleware;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class LegajoSoloLectura
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->route('usuario');
        if ($request->isMethodSafe() || ! $usuario || ! $request->is('api/v1/expedientes/*')
            || $request->is('api/v1/expedientes/*/legajo') || $request->is('api/v1/expedientes/*/legajos/*/cerrar')
            || $request->is('api/v1/expedientes/*/legajos/*/reabrir')) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next, $usuario): Response {
            Usuario::query()->whereKey($usuario)->lockForUpdate()->firstOrFail();
            $legajos = Legajo::query()->where('usuario_id', $usuario)->lockForUpdate()->get();
            abort_if($legajos->isNotEmpty() && ! $legajos->contains('estado_legajo', 'activo'), 409,
                'El legajo está cerrado. Solo se permite visualizar y descargar.');
            $documento = $request->route('documento');
            if ($documento) {
                $registro = DocumentoUsuario::query()->where('usuario_id', $usuario)->find($documento);
                abort_if($registro && $legajos->firstWhere('id', $registro->legajo_id)?->estado_legajo !== 'activo', 409,
                    'El documento pertenece a un legajo cerrado. Solo se permite visualizar y descargar.');
            }

            return $next($request);
        });
    }
}
