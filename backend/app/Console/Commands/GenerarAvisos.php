<?php

namespace App\Console\Commands;

use App\Jobs\GenerarAvisosInstitucion;
use App\Models\Plataforma\Institucion;
use App\Support\GeneradorAvisos;
use App\Support\Plataforma\ConexionInstitucion;
use Illuminate\Console\Command;
use Throwable;

class GenerarAvisos extends Command
{
    protected $signature = 'avisos:generar {--todas : Todas las instituciones aprovisionadas} {--cola : Procesar mediante la cola avisos}';

    protected $description = 'Genera avisos internos administrativos sin repetir el mismo asunto y fecha';

    public function handle(): int
    {
        $total = 0;
        $fallos = 0;
        $procesar = function () use (&$total): void {
            if ($this->option('cola')) {
                GenerarAvisosInstitucion::dispatch()->onQueue('avisos');
            } else {
                $total += GeneradorAvisos::generar();
            }
        };
        if ($this->option('todas')) {
            foreach (Institucion::query()->whereNotNull('aprovisionada_en')->where('estado', Institucion::ACTIVA)->cursor() as $institucion) {
                if (! $institucion->operativa()) {
                    continue;
                }
                try {
                    ConexionInstitucion::usar($institucion, $procesar);
                } catch (Throwable $error) {
                    report($error);
                    $fallos++;
                    $this->error('No se pudieron generar avisos para '.$institucion->slug);
                }
            }
        } else {
            $procesar();
        }
        if ($this->option('cola')) {
            $this->info('Procesamiento delegado a la cola avisos.');
        }
        $this->info("Avisos nuevos en ejecución directa: {$total}. Instituciones con errores: {$fallos}.");

        return $fallos ? self::FAILURE : self::SUCCESS;
    }
}
