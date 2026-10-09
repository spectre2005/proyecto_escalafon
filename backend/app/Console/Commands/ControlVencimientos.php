<?php

namespace App\Console\Commands;

use App\Models\CicloVigencia;
use App\Support\AvisosVencimiento;
use App\Support\VigenciaLegajo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ControlVencimientos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'legajos:controlar-vencimientos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calcula vigencia docente, prepara requisitos y genera avisos únicos a siete días';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $bloqueo = Cache::lock('legajos.control-vencimientos', 3600);
        if (! $bloqueo->get()) {
            $this->info('Ya hay un control de vencimientos en ejecución.');

            return self::SUCCESS;
        }
        $resultado = ['inicializados' => 0, 'revisados' => 0, 'errores' => 0];
        try {
            $resultado['inicializados'] = VigenciaLegajo::sincronizar();
            CicloVigencia::query()->where('actual', true)->whereHas('legajo', fn ($q) => $q->where('estado_legajo', 'activo'))->chunkById(100, function ($ciclos) use (&$resultado): void {
                foreach ($ciclos as $ciclo) {
                    try {
                        DB::transaction(function () use ($ciclo): void {
                            $ciclo = VigenciaLegajo::bloquearActual($ciclo);
                            $calculo = VigenciaLegajo::calcular($ciclo->categoria, $ciclo->fecha_base->toDateString(), $ciclo->anios);
                            if ($ciclo->estado_vigencia !== $calculo['estado_vigencia']) {
                                $ciclo->update(['estado_vigencia' => $calculo['estado_vigencia']]);
                                VigenciaLegajo::auditar($ciclo, 'vigencia.cambiar_estado', null, ['estado' => $calculo['estado_vigencia']]);
                            }
                            VigenciaLegajo::asegurarRequisitos($ciclo);
                            if ($ciclo->fecha_aviso->toDateString() <= VigenciaLegajo::hoy()->toDateString()) {
                                AvisosVencimiento::crear($ciclo);
                            }
                        });
                        $resultado['revisados']++;
                    } catch (\Throwable $exception) {
                        report($exception);
                        $resultado['errores']++;
                    }
                }
            });
            Cache::forever('vencimientos.ultimo_control', [...$resultado, 'fecha' => now()->toIso8601String()]);
            $this->info(json_encode($resultado, JSON_THROW_ON_ERROR));

            return $resultado['errores'] ? self::FAILURE : self::SUCCESS;
        } finally {
            $bloqueo->release();
        }
    }
}
