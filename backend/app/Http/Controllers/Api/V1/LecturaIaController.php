<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\LeerDocumentoConIa;
use App\Models\LecturaIa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Cuánto acierta la lectura de PDF con IA. El formulario informa, al enviarse, qué pasó con cada dato pedido; aquí se
 * guarda (solo el resultado por campo, nunca valores) y se resume por campo para administración.
 */
class LecturaIaController extends Controller
{
    /**
     * Estado y avance de una lectura propia; al terminar, su resultado (de la caché cifrada). Una lectura sin avances
     * en LecturaIa::MINUTOS_SIN_AVANCE se da por interrumpida.
     */
    public function mostrar(Request $request, LecturaIa $lectura): JsonResponse
    {
        abort_unless($lectura->registrado_por === $request->user()->id, 403, 'Solo quien hizo la lectura puede verla.');
        if ($lectura->interrumpida()) {
            $lectura->update(['estado' => 'error', 'error' => 'La lectura se interrumpió (p. ej. se reinició el servidor). Vuelve a intentarlo.']);
        }
        $datos = ['lectura_id' => $lectura->id, 'estado' => $lectura->estado, 'pagina_actual' => $lectura->pagina_actual,
            'paginas_leer' => $lectura->paginas_leer, 'paginas_documento' => $lectura->paginas_documento, 'error' => $lectura->error];
        if ($lectura->estado === 'lista') {
            $cifrado = Cache::get($lectura->claveResultado());
            if (! is_string($cifrado)) {
                return response()->json(['data' => [...$datos, 'estado' => 'error',
                    'error' => 'El resultado de esta lectura ya no está disponible ('.LeerDocumentoConIa::RESULTADO_MINUTOS.' min). Vuelve a leer el PDF.']])
                    ->header('Cache-Control', 'private, no-store');
            }
            $datos += json_decode(Crypt::decryptString($cifrado), true, flags: JSON_THROW_ON_ERROR);
            $datos['aviso'] = self::aviso($lectura);
        }

        return response()->json(['data' => $datos])->header('Cache-Control', 'private, no-store');
    }

    /** Cuántas páginas se leyeron y, si el PDF tenía más que el máximo, que no se leyeron todas. */
    public static function aviso(LecturaIa $lectura): string
    {
        $leidas = (int) $lectura->paginas_leer;
        $total = (int) ($lectura->paginas_documento ?: $leidas);
        $paginas = $leidas === 1 ? 'Se leyó 1 página.' : "Se leyeron {$leidas} páginas.";
        if ($total > $leidas) {
            $paginas = "Se leyeron las primeras {$leidas} de {$total} páginas (máximo configurado). Revisa a mano las demás.";
        }

        return $paginas.' Revisa los datos contra el PDF antes de guardar. La lectura no acredita autenticidad.';
    }

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
