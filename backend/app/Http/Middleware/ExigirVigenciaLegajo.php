<?php

namespace App\Http\Middleware;

use App\Models\Legajo;
use App\Support\VigenciaLegajo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExigirVigenciaLegajo
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (config('vencimientos.rutas_requieren_vigencia', []) as $ruta) {
            if (! $request->is($ruta)) {
                continue;
            }
            $legajo = $request->route('legajo');
            if (! $legajo instanceof Legajo) {
                $legajo = $legajo ? Legajo::query()->find($legajo) : Legajo::query()->where('usuario_id', $request->route('usuario'))
                    ->where('estado_legajo', 'activo')->first();
            }
            abort_unless($legajo, 422, 'El trámite requiere un legajo activo identificado.');
            abort_unless($request->user()->can('legajo.ver') || ($request->user()->can('legajo.propio') && $legajo->usuario_id === $request->user()->id), 403);
            $ciclo = $legajo->cicloActual;
            abort_unless($ciclo, 422, 'Falta la fecha base acreditada para comprobar la vigencia.');
            abort_if(VigenciaLegajo::calcular($ciclo->categoria, $ciclo->fecha_base->toDateString(), $ciclo->anios)['estado_vigencia'] === 'vencido', 423, 'El legajo está vencido. Completa la renovación antes de realizar este trámite.');
        }

        return $next($request);
    }
}
