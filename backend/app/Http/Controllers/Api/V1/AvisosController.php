<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\EnviarAvisoCorreo;
use App\Models\NotificacionUsuario;
use App\Support\AuditoriaInstitucional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AvisosController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate(['leida' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1']);
        $consulta = NotificacionUsuario::query()->where('usuario_id', $request->user()->id);
        $sinLeer = (clone $consulta)->where('leida', false)->count();
        if (array_key_exists('leida', $datos)) {
            $consulta->where('leida', (bool) $datos['leida']);
        }
        $pagina = $consulta->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return response()->json([...$pagina->toArray(), 'sin_leer' => $sinLeer, 'correo_habilitado' => (bool) config('avisos.correo_habilitado')]);
    }

    public function reintentarCorreo(Request $request, string $aviso): JsonResponse
    {
        abort_unless(config('avisos.correo_habilitado'), 409, 'El canal de correo está desactivado.');
        abort_if(array_diff(array_keys($request->all()), ['motivo']) !== [], 422, 'Campo no permitido.');
        $datos = $request->validate(['motivo' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($request, $aviso, $datos): void {
            $registro = NotificacionUsuario::query()->where('usuario_id', $request->user()->id)->lockForUpdate()->findOrFail($aviso);
            abort_unless($registro->correo_estado === 'error_previo', 409, 'Solo se reintenta un error anterior al envío. Las entregas inciertas requieren revisión del operador.');
            $registro->forceFill(['correo_estado' => 'pendiente', 'correo_error' => null])->save();
            AuditoriaInstitucional::registrar('notificaciones_usuario', $registro->id, 'aviso.correo_reintentado', $request, $request->user()->id, null, ['motivo' => $datos['motivo']]);
            EnviarAvisoCorreo::dispatch($registro->id)->onQueue('avisos')->afterCommit();
        });

        return response()->json(['message' => 'Reintento encolado.']);
    }

    public function leer(Request $request, string $aviso): JsonResponse
    {
        abort_unless($request->all() === [], 422, 'Esta acción no recibe datos.');
        $registro = NotificacionUsuario::query()->where('usuario_id', $request->user()->id)->findOrFail($aviso);
        NotificacionUsuario::query()->whereKey($registro->id)->where('leida', false)->update(['leida' => true, 'fecha_lectura' => now()]);

        return response()->json(['data' => $registro->refresh()]);
    }
}
