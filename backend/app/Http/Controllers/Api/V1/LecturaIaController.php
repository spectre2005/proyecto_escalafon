<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LecturaIa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Cuánto acierta la lectura de PDF con IA. El formulario informa, al enviarse, qué pasó con cada dato pedido; aquí se
 * guarda (solo el resultado por campo, nunca valores) y se resume por campo para administración.
 */
class LecturaIaController extends Controller
{
    /** Resultado de una lectura propia. Se puede volver a enviar (p. ej. si el primer guardado falló): manda el último. */
    public function resultado(Request $request, LecturaIa $lectura): JsonResponse
    {
        abort_unless($lectura->registrado_por === $request->user()->id, 403, 'Solo quien hizo la lectura puede informar su resultado.');
        $datos = $request->validate([
            'resultado' => 'present|array|max:22',
            'resultado.*' => 'required|string|in:'.implode(',', LecturaIa::RESULTADOS),
        ]);
        $ajenos = array_diff(array_keys($datos['resultado']), $lectura->campos_pedidos);
        if ($ajenos !== []) {
            throw ValidationException::withMessages(['resultado' => 'Hay campos que no se pidieron en esta lectura: '.implode(', ', $ajenos).'.']);
        }
        $lectura->update(['resultado' => $datos['resultado'], 'evaluado_en' => now()]);

        return response()->json(['data' => ['id' => $lectura->id, 'evaluado_en' => $lectura->evaluado_en]]);
    }

    /** Resumen de acierto por campo en los últimos días (por defecto 90). Acierto = aceptados / (aceptados + corregidos + borrados). */
    public function precision(Request $request): JsonResponse
    {
        $dias = (int) ($request->validate(['dias' => 'sometimes|integer|min:1|max:365'])['dias'] ?? 90);
        $lecturas = LecturaIa::query()->where('created_at', '>=', now()->subDays($dias))->get(['titular', 'modelo', 'resultado', 'segundos']);
        $evaluadas = $lecturas->whereNotNull('resultado');

        $porCampo = [];
        foreach ($evaluadas as $lectura) {
            foreach ($lectura->resultado as $campo => $estado) {
                $porCampo[$campo] ??= array_fill_keys(LecturaIa::RESULTADOS, 0);
                $porCampo[$campo][$estado]++;
            }
        }
        $filas = collect($porCampo)->map(function (array $conteo, string $campo): array {
            $propuestos = $conteo['aceptado'] + $conteo['corregido'] + $conteo['borrado'];

            return ['campo' => $campo, ...$conteo, 'propuestos' => $propuestos,
                'acierto' => $propuestos > 0 ? (int) round($conteo['aceptado'] / $propuestos * 100) : null];
        })->sortByDesc('propuestos')->values();
        $propuestos = $filas->sum('propuestos');

        return response()->json(['data' => [
            'dias' => $dias,
            'lecturas' => $lecturas->count(),
            'evaluadas' => $evaluadas->count(),
            'acierto_general' => $propuestos > 0 ? (int) round($filas->sum('aceptado') / $propuestos * 100) : null,
            'segundos_promedio' => $lecturas->whereNotNull('segundos')->isEmpty() ? null : round($lecturas->whereNotNull('segundos')->avg('segundos'), 1),
            'titular' => $lecturas->whereNotNull('titular')->countBy('titular')->all(),
            'modelos' => $lecturas->countBy('modelo')->all(),
            'por_campo' => $filas->all(),
        ]]);
    }
}
