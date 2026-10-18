<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionFuncionalidad;
use App\Support\ConfiguracionFuncionalidades;
use App\Support\ModulosSistema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Consulta y cambio de la configuración de funcionalidades (permiso configuracion.modulos, asignación explícita).
 * Las restricciones, el motivo y la auditoría los aplica App\Support\ConfiguracionFuncionalidades.
 */
class ConfiguracionFuncionalidadController extends Controller
{
    /** Para cualquier sesión: entradas de menú y secciones que se ocultan, y tipos de documento que no se ofrecen. */
    public function publico(): JsonResponse
    {
        return response()->json(['data' => [...ConfiguracionFuncionalidades::estadoPublico(), 'modulos_sistema' => collect(ModulosSistema::estados())->pluck('activa', 'codigo')]])->header('Cache-Control', 'private, no-store');
    }

    public function index(): JsonResponse
    {
        $guardadas = ConfiguracionFuncionalidad::query()->with('updatedBy')->get()->keyBy('codigo');
        $estados = array_map(fn (array $estado): array => $this->presentar($estado, $guardadas->get($estado['codigo'])),
            array_values(ConfiguracionFuncionalidades::estados()));

        return response()->json(['data' => $estados])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $codigo): JsonResponse
    {
        abort_unless(isset(ConfiguracionFuncionalidades::catalogo()[$codigo]), 404, 'La funcionalidad no existe.');
        $desconocidos = array_diff(array_keys($request->all()), ['activa', 'motivo']);
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }
        $data = $request->validate(['activa' => 'required|boolean', 'motivo' => 'required|string|max:500']);
        $registro = ConfiguracionFuncionalidades::cambiar($codigo, (bool) $data['activa'], $data['motivo'], $request->user(), $request);

        return response()->json(['data' => $this->presentar(ConfiguracionFuncionalidades::estados()[$codigo], $registro->load('updatedBy'))])
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * Estado efectivo, registros guardados (se avisan al desactivar: quedan ocultos, no se borran) y el último cambio
     * guardado (motivo, quién y cuándo).
     */
    private function presentar(array $estado, ?ConfiguracionFuncionalidad $guardada): array
    {
        $autor = $guardada?->updatedBy;

        return [...$estado, 'registros' => ConfiguracionFuncionalidades::registros($estado['codigo']), 'ultimo_cambio' => $guardada ? [
            'motivo' => $guardada->motivo,
            'por' => $autor ? trim($autor->nombres.' '.$autor->apellido_paterno.' '.$autor->apellido_materno) : null,
            'fecha' => $guardada->updated_at?->toIso8601String(),
        ] : null];
    }
}
