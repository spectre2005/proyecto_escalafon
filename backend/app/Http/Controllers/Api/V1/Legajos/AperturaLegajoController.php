<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaEvento;
use App\Models\Legajo;
use App\Models\Usuario;
use App\Support\AperturaLegajo;
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
 * ubicación física y si una persona con legajo pasivo abre uno nuevo (hoy se permite).
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
            $antes = $registro->only(['estado_legajo', 'fecha_pasivo']);
            $registro->update(['estado_legajo' => 'pasivo', 'fecha_pasivo' => today()]);
            $this->registrarCambio($request, $registro, 'legajo.cerrar', $antes);

            return $registro;
        });

        return response()->json(['data' => $registro->only(['id', 'estado_legajo', 'fecha_pasivo'])]);
    }

    public function reabrir(Request $request, string $usuario, string $legajo): JsonResponse
    {
        abort_if($request->all() !== [], 422, 'La reapertura no recibe datos adicionales.');
        $registro = DB::transaction(function () use ($usuario, $legajo, $request): Legajo {
            Usuario::query()->whereKey($usuario)->lockForUpdate()->firstOrFail();
            $registro = Legajo::query()->where('usuario_id', $usuario)->lockForUpdate()->findOrFail($legajo);
            abort_unless(in_array($registro->estado_legajo, ['pasivo', 'cerrado'], true), 409, 'Solo se puede reabrir un legajo cerrado.');
            abort_if(Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->exists(), 409,
                'La persona ya tiene otro legajo activo.');
            $antes = $registro->only(['estado_legajo', 'fecha_pasivo']);
            $registro->update(['estado_legajo' => 'activo', 'fecha_pasivo' => null]);
            $this->registrarCambio($request, $registro, 'legajo.reabrir', $antes);

            return $registro;
        });

        return response()->json(['data' => $registro->only(['id', 'numero_legajo', 'estado_legajo', 'fecha_apertura', 'fecha_pasivo'])]);
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
        $legajo = AperturaLegajo::abrir($usuario);
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
