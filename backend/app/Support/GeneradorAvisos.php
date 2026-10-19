<?php

namespace App\Support;

use App\Jobs\EnviarAvisoCorreo;
use App\Models\MovimientoCustodiaLegajo;
use App\Models\NotificacionUsuario;
use App\Models\ProcesoFiscalizacion;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class GeneradorAvisos
{
    public static function generar(): int
    {
        NotificacionUsuario::query()->where('correo_estado', 'enviando')->where('correo_intento_en', '<', now()->subMinutes(10))->update(['correo_estado' => 'incierto', 'correo_error' => 'Entrega interrumpida; verifica el proveedor antes de reenviar.']);
        $nuevos = 0;
        $hoy = CarbonImmutable::now('America/Lima')->startOfDay();
        Usuario::query()->where('activo', true)->whereHas('usuarioRolesPorUsuario')->orderBy('id')->chunkById(100, function ($usuarios) use ($hoy, &$nuevos): void {
            foreach ($usuarios as $actor) {
                if (! $actor->can('sistema.administrar')) {
                    continue;
                }
                if ($actor->can('legajo.ver')) {
                    $alertas = ConsultaVencimientos::alertas($actor, $hoy, 30, null);
                    foreach (DB::query()->fromSub($alertas, 'a')->orderBy('alerta_id')->cursor() as $alerta) {
                        $nuevos += self::guardar($actor, $alerta->origen, $alerta->alerta_id, substr($alerta->fecha, 0, 10),
                            $alerta->origen === 'requerimiento' ? 'Requerimiento atrasado' : 'Vencimiento documental o laboral',
                            'Hay un asunto que requiere revisión administrativa. Fecha de referencia: '.substr($alerta->fecha, 0, 10).'.',
                            $alerta->origen === 'requerimiento' ? '/requerimientos' : '/vencimientos',
                            $alerta->origen === 'documento' ? $alerta->alerta_id : null, $alerta->origen === 'requerimiento' ? $alerta->alerta_id : null);
                    }
                }
                foreach (ProcesoFiscalizacion::query()->where('estado', 'abierto')->whereDate('fecha_limite', '<', $hoy->toDateString())->cursor() as $proceso) {
                    $nuevos += self::guardar($actor, 'fiscalizacion', $proceso->id, $proceso->fecha_limite->toDateString(), 'Fiscalización fuera de plazo', 'Revisa los casos abiertos que superaron su fecha límite.', '/fiscalizacion');
                }
                foreach (MovimientoCustodiaLegajo::query()->where('tipo_movimiento', 'prestamo')->whereNull('devuelto_en')->whereNull('fecha_retorno')
                    ->whereDate('fecha_devolucion_prevista', '<', $hoy->toDateString())->cursor() as $movimiento) {
                    $nuevos += self::guardar($actor, 'prestamo', $movimiento->id, $movimiento->fecha_devolucion_prevista->toDateString(), 'Préstamo pendiente de devolución', 'Revisa los préstamos que superaron su fecha de devolución prevista.', '/custodia');
                }
            }
        });

        return $nuevos;
    }

    private static function guardar(Usuario $actor, string $tipo, string $origen, string $fecha, string $titulo, string $mensaje, string $enlace, ?string $documento = null, ?string $requerimiento = null): int
    {
        $aviso = NotificacionUsuario::query()->firstOrCreate(['usuario_id' => $actor->id, 'clave_deduplicacion' => hash('sha256', $tipo.'|'.$origen.'|'.$fecha)],
            ['tipo' => $tipo, 'titulo' => $titulo, 'mensaje' => $mensaje, 'enlace' => $enlace, 'documento_id' => $documento, 'requerimiento_id' => $requerimiento, 'leida' => false]);

        if (config('avisos.correo_habilitado') && ($aviso->correo_estado ?? 'pendiente') === 'pendiente') {
            EnviarAvisoCorreo::dispatch($aviso->id)->onQueue('avisos')->afterCommit();
        }

        return (int) $aviso->wasRecentlyCreated;
    }
}
