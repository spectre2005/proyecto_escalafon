<?php

namespace App\Console\Commands;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\ConexionInstitucion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProcesarVencimientosInstituciones extends Command
{
    protected $signature = 'plataforma:procesar-vencimientos {accion=controlar : controlar o enviar}';

    protected $description = 'Procesa vigencia o avisos dentro del contexto aislado de cada institución activa';

    public function handle(): int
    {
        $comando = match ($this->argument('accion')) {
            'controlar' => 'legajos:controlar-vencimientos',
            'enviar' => 'legajos:enviar-avisos',
            default => null,
        };
        if ($comando === null) {
            $this->error('La acción debe ser controlar o enviar.');

            return self::INVALID;
        }
        if (config('plataforma.modo') !== 'subdominio') {
            return $this->procesar($comando);
        }
        $fallidas = 0;
        foreach (Institucion::query()->where('estado', Institucion::ACTIVA)->whereNotNull('aprovisionada_en')->orderBy('slug')->cursor() as $institucion) {
            try {
                $codigo = ConexionInstitucion::usar($institucion, fn () => $this->procesar($comando));
                if ($codigo !== self::SUCCESS) {
                    $fallidas++;
                    $this->error('Falló el procesamiento de '.$institucion->slug.'.');
                }
            } catch (Throwable $error) {
                report($error);
                $fallidas++;
                $this->error('Falló '.$institucion->slug.': '.$error->getMessage());
            }
        }

        return $fallidas === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function procesar(string $comando): int
    {
        if (! Schema::hasTable('ciclos_vigencia_legajo')) {
            $this->error('Faltan migraciones de vencimientos en esta institución.');

            return self::FAILURE;
        }

        return $this->call($comando);
    }
}
