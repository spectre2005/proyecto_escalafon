<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaEvento;
use App\Models\Legajo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\RegimenesLaborales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Completar el tipo de vínculo de un vínculo cerrado (con fecha de cese) que quedó Sin definir. Es la única escritura
 * permitida en un vínculo cerrado: solo el tipo, solo si está vacío, y una vez puesto no se cambia. Queda en
 * auditoria_eventos. Los vínculos abiertos lo corrigen con «Corregir vínculo» (Seccion02Controller::updateVinculo).
 */
class VinculoTipoController extends Controller
{
    public function completar(Request $request, string $usuario, string $vinculo): JsonResource|JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        abort_unless(Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->exists(), 409,
            'La persona no tiene un legajo abierto. Primero se debe abrir su legajo.');
        $data = $request->validate(['regimen_laboral' => ['required', Rule::in(array_keys(RegimenesLaborales::TIPOS_VINCULO))]]);
        if ($extra = array_diff(array_keys($request->all()), ['regimen_laboral'])) {
            return response()->json(['message' => 'Solo se completa el tipo de vínculo; el resto del vínculo cerrado no se corrige.',
                'errors' => array_fill_keys(array_values($extra), ['Campo no permitido.'])], 422);
        }

        return DB::transaction(function () use ($request, $usuario, $vinculo, $data): JsonResource|JsonResponse {
            $registro = VinculoLaboral::query()->where('usuario_id', $usuario)->lockForUpdate()->findOrFail($vinculo);
            $rechazo = match (true) {
                $registro->anulado => 'El vínculo está anulado: no se corrige.',
                $registro->fecha_cese === null => 'El vínculo está abierto: el tipo se corrige con «Corregir vínculo».',
                $registro->regimen_laboral !== null => 'El tipo de vínculo ya está definido y no se cambia.',
                default => null,
            };
            if ($rechazo) {
                return response()->json(['message' => $rechazo], 409);
            }
            $registro->update(['regimen_laboral' => $data['regimen_laboral']]);
            $this->auditar($registro, $request);

            return new JsonResource($registro->refresh());
        });
    }

    private function auditar(VinculoLaboral $vinculo, Request $request): void
    {
        $datos = [
            'fecha_evento' => now()->format('Y-m-d H:i:s'), 'actor_usuario_id' => $request->user()->getKey(), 'usuario_titular_id' => $vinculo->usuario_id,
            'accion' => 'vinculo.completar_tipo', 'entidad' => 'vinculos_laborales', 'entidad_id' => $vinculo->getKey(),
            'datos_antes' => json_encode(['regimen_laboral' => null], JSON_THROW_ON_ERROR),
            'datos_despues' => json_encode(['regimen_laboral' => $vinculo->regimen_laboral], JSON_THROW_ON_ERROR),
            'ip_origen' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            'request_id' => (string) Str::uuid(),
            'hash_previo' => AuditoriaEvento::query()->where('entidad', 'vinculos_laborales')->where('entidad_id', $vinculo->getKey())
                ->orderByDesc('fecha_evento')->orderByDesc('id')->value('hash_registro'),
        ];
        AuditoriaEvento::query()->create([...$datos, 'hash_registro' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR))]);
    }
}
