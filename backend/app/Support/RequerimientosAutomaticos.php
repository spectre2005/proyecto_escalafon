<?php

namespace App\Support;

use App\Models\Legajo;
use App\Models\RequerimientoDocumento;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class RequerimientosAutomaticos
{
    /** Genera solo pendientes confirmados; nunca incorpora ni aprueba documentos. */
    public static function sincronizar(Usuario $actor): int
    {
        abort_unless($actor->can('sistema.administrar'), 403);
        $creados = 0;
        foreach (ConsultaReportes::pendientesAutomaticos($actor) as $fila) {
            $clave = hash('sha256', implode('|', [$fila['usuario_id'], $fila['id'], $fila['estado_codigo'], $fila['fecha'] ?? '']));
            $creados += DB::transaction(function () use ($actor, $fila, $clave): int {
                Usuario::query()->whereKey($fila['usuario_id'])->lockForUpdate()->firstOrFail();
                $activo = Legajo::query()->whereKey($fila['legajo_id'])->where('usuario_id', $fila['usuario_id'])->where('estado_legajo', 'activo')->lockForUpdate()->first();
                if (! $activo || RequerimientoDocumento::query()->where('clave_automatica', $clave)->exists()) {
                    return 0;
                }
                $abierto = RequerimientoDocumento::query()->where('usuario_id', $fila['usuario_id'])->where('tipo_documento_id', $fila['tipo_documento_id'])
                    ->where('clase', 'documentos')->whereIn('estado', ['abierto', 'observado', 'en_revision'])->exists();
                if ($abierto) {
                    return 0;
                }
                $motivo = ['faltante' => 'Presentar documento faltante', 'observado' => 'Subsanar documento observado', 'vencido' => 'Actualizar documento vencido'][$fila['estado_codigo']];
                $descripcion = mb_substr($motivo.': '.$fila['detalle'], 0, 300);
                $dias = max(1, Parametros::entero('requerimiento_plazo_dias_calendario'));
                $r = RequerimientoDocumento::query()->create([
                    'clave_automatica' => $clave, 'clase' => 'documentos', 'usuario_id' => $fila['usuario_id'], 'tipo_documento_id' => $fila['tipo_documento_id'],
                    'origen' => 'automatico', 'descripcion' => $descripcion, 'fecha_requerimiento' => now('America/Lima')->toDateString(),
                    'plazo_dias_habiles' => 0, 'fecha_limite' => now('America/Lima')->addDays($dias)->toDateString(),
                    'estado' => 'abierto', 'solicitado_por' => $actor->id,
                    'seguimiento' => [['accion' => 'detección automática', 'mensaje' => $descripcion,
                        'motivo' => $fila['estado_codigo'], 'autor' => null, 'fecha' => now()->toIso8601String()]],
                ]);

                return 1;
            });
        }

        return $creados;
    }
}
