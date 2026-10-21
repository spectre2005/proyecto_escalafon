<?php

namespace App\Console\Commands;

use App\Models\Legajo;
use App\Models\NotificacionUsuario;
use App\Models\Plataforma\Institucion;
use App\Models\RequerimientoDocumento;
use App\Models\Usuario;
use App\Support\Plataforma\ConexionInstitucion;
use App\Support\RequerimientosAutomaticos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecordarSolicitudesLegajo extends Command
{
    protected $signature = 'legajo:recordar-solicitudes';

    protected $description = 'Registra avisos internos diarios para solicitudes próximas a vencer o atrasadas';

    public function handle(): int
    {
        if (config('plataforma.modo') === 'subdominio' && ! app()->bound(Institucion::class)) {
            $resultado = self::SUCCESS;
            foreach (Institucion::query()->get() as $institucion) {
                if (! $institucion->operativa() || ! $institucion->aprovisionada()) {
                    continue;
                }
                try {
                    ConexionInstitucion::usar($institucion, fn () => $this->recordar());
                } catch (\Throwable $e) {
                    report($e);
                    $this->error('No se pudieron generar recordatorios para '.$institucion->slug);
                    $resultado = self::FAILURE;
                }
            }

            return $resultado;
        }

        return $this->recordar();
    }

    private function recordar(): int
    {
        $actor = Usuario::query()->where('activo', true)->whereHas('usuarioRolesPorUsuario.rol', fn ($q) => $q->where('codigo', 'ADMIN'))->get()
            ->first(fn (Usuario $usuario): bool => $usuario->can('sistema.administrar'));
        if ($actor) {
            $creados = RequerimientosAutomaticos::sincronizar($actor);
            $this->info("Requerimientos automáticos creados: {$creados}");
        }
        $total = 0;
        RequerimientoDocumento::query()->whereIn('estado', ['abierto', 'observado'])
            ->whereIn('usuario_id', Legajo::query()->where('estado_legajo', 'activo')->select('usuario_id'))
            ->whereRaw('COALESCE(prorroga_hasta, fecha_limite) <= ?', [now('America/Lima')->addDays(3)->toDateString()])
            ->where(fn ($q) => $q->whereNull('recordado_en')->orWhere('recordado_en', '<', now('America/Lima')->startOfDay()->utc()))
            ->select('id')->chunkById(100, function ($filas) use (&$total): void {
                foreach ($filas as $fila) {
                    DB::transaction(function () use ($fila, &$total): void {
                        $r = RequerimientoDocumento::query()->lockForUpdate()->findOrFail($fila->id);
                        if ($r->recordado_en?->timezone('America/Lima')->isToday() || ! in_array($r->estado, ['abierto', 'observado'], true)) {
                            return;
                        }
                        $mensaje = 'Tienes pendiente: '.$r->descripcion.'. Fecha límite: '.($r->prorroga_hasta ?? $r->fecha_limite)->format('d/m/Y').'.';
                        NotificacionUsuario::query()->create(['usuario_id' => $r->usuario_id, 'tipo' => 'solicitud_legajo', 'titulo' => 'Recordatorio de documentación', 'mensaje' => $mensaje, 'requerimiento_id' => $r->id]);
                        $r->update(['recordado_en' => now(), 'seguimiento' => [...($r->seguimiento ?? []), ['accion' => 'recordatorio automático', 'mensaje' => $mensaje, 'autor' => null, 'fecha' => now()->toIso8601String()]]]);
                        $total++;
                    });
                }
            });
        $this->info("Recordatorios internos registrados: {$total}");

        return self::SUCCESS;
    }
}
