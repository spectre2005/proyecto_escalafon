<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaEvento;
use App\Models\Legajo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\AperturaLegajo;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Abrir el legajo de una persona desde la ficha (permiso legajo.abrir): crea el legajo activo y las filas de sus
 * 12 secciones normativas (art. 3.1; legajo_secciones, con ultimo_folio = 0) en una sola transacción. Un solo
 * legajo activo por persona. La sección adicional de legajos pasivos (art. 3.2, "13") es optativa y no se
 * inicializa aquí, aunque exista en el catálogo: si llega a usarse, Folios::reservar crea su fila.
 * TODO pendiente de confirmar con el cliente: formato_legajo (hoy el valor por defecto del esquema, digital),
 * ubicación física. Una reincorporación abre un legajo nuevo; los cerrados se conservan.
 */
class AperturaLegajoController extends Controller
{
    public function cerrar(Request $request, string $usuario, string $legajo): JsonResponse
    {
        abort_if($request->all() !== [], 422, 'El cierre no recibe datos adicionales.');
        $registro = DB::transaction(function () use ($usuario, $legajo, $request): Legajo {
            Usuario::query()->whereKey($usuario)->lockForUpdate()->firstOrFail();
            $registro = Legajo::query()->where('usuario_id', $usuario)->lockForUpdate()->findOrFail($legajo);
            abort_unless($registro->estado_legajo === 'activo', 409, 'El legajo ya está cerrado.');
            abort_if(VinculoLaboral::query()->where('usuario_id', $usuario)->where('anulado', false)->whereNull('fecha_cese')->lockForUpdate()->first(),
                409, 'No se puede cerrar el legajo: hay vínculos laborales sin cese. Registra primero su cese y desvinculación en la sección 11.');
            $antes = $registro->only(['estado_legajo', 'fecha_pasivo']);
            $registro->update(['estado_legajo' => 'pasivo', 'fecha_pasivo' => today()]);
            $this->registrarCambio($request, $registro, 'legajo.cerrar', $antes);

            return $registro;
        });

        return response()->json(['data' => $registro->only(['id', 'estado_legajo', 'fecha_pasivo'])]);
    }

    public function reabrir(Request $request, string $usuario, string $legajo): JsonResponse
    {
        Legajo::query()->where('usuario_id', $usuario)->findOrFail($legajo);

        return response()->json(['message' => 'Los legajos cerrados se conservan como históricos. Para una reincorporación abre un nuevo legajo de la misma persona.'], 409);
    }

    private function registrarCambio(Request $request, Legajo $legajo, string $accion, array $antes): void
    {
        $datos = [
            'fecha_evento' => now(), 'actor_usuario_id' => $request->user()->getAuthIdentifier(),
            'usuario_titular_id' => $legajo->usuario_id, 'accion' => $accion, 'entidad' => 'legajos', 'entidad_id' => $legajo->id,
            'datos_antes' => json_encode($antes, JSON_THROW_ON_ERROR),
            'datos_despues' => json_encode($legajo->only(['estado_legajo', 'fecha_pasivo']), JSON_THROW_ON_ERROR),
            'ip_origen' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            'request_id' => (string) Str::uuid(),
            'hash_previo' => AuditoriaEvento::query()->where('entidad', 'legajos')->where('entidad_id', $legajo->id)->orderByDesc('id')->value('hash_registro'),
        ];
        AuditoriaEvento::query()->create([...$datos, 'hash_registro' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR))]);
    }

    public function store(Request $request, string $usuario): JsonResponse
    {
        abort_if($request->all() !== [], 422, 'La apertura no recibe datos: el número y la fecha los asigna el sistema.');
        // La apertura y su evento en una sola transacción (AperturaLegajo::abrir se anida en ella).
        $legajo = DB::transaction(function () use ($usuario, $request): ?Legajo {
            $legajo = AperturaLegajo::abrir($usuario);
            if ($legajo) {
                Auditoria::registrar($request, 'legajo.abrir', 'legajos', $legajo->id, $usuario, null, $legajo->getAttributes());
            }

            return $legajo;
        });
        if (! $legajo) {
            return response()->json(['message' => 'La persona ya tiene un legajo activo.'], 409);
        }

        return response()->json(['data' => [
            ...$legajo->only(['id', 'usuario_id', 'numero_legajo', 'estado_legajo', 'formato_legajo']),
            'fecha_apertura' => $legajo->fecha_apertura->format('Y-m-d'),
            'secciones' => $legajo->legajoSecciones()->count(),
        ]], 201);
    }
}
