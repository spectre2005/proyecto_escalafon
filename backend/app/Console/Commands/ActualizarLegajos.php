<?php

namespace App\Console\Commands;

use App\Models\Legajo;
use App\Models\Plataforma\Institucion;
use App\Support\CierreLegajo;
use App\Support\Plataforma\ConexionInstitucion;
use Illuminate\Console\Command;
use Throwable;

class ActualizarLegajos extends Command
{
    protected $signature = 'legajos:actualizar {--todas : Todas las instituciones aprovisionadas}';

    protected $description = 'Pasa a pasivo los legajos cuyo último vínculo efectivo terminó';

    public function handle(): int
    {
        $errores = 0;
        $cerrados = 0;
        $procesar = function () use (&$errores, &$cerrados): void {
            Legajo::query()->where('estado_legajo', 'activo')->orderBy('id')->chunkById(100, function ($legajos) use (&$errores, &$cerrados): void {
                foreach ($legajos as $legajo) {
                    try {
                        $cerrados += (int) CierreLegajo::actualizar($legajo->usuario_id);
                    } catch (Throwable $error) {
                        report($error);
                        $errores++;
                        $this->error('No se pudo actualizar el legajo '.$legajo->id);
                    }
                }
            });
        };
        if ($this->option('todas')) {
            foreach (Institucion::query()->whereNotNull('aprovisionada_en')->where('estado', Institucion::ACTIVA)->cursor() as $institucion) {
                if (! $institucion->operativa()) {
                    continue;
                }
                ConexionInstitucion::usar($institucion, $procesar);
            }
        } else {
            $procesar();
        }
        $this->info("Legajos pasados a pasivo: {$cerrados}. Errores: {$errores}.");

        return $errores ? self::FAILURE : self::SUCCESS;
    }
}
