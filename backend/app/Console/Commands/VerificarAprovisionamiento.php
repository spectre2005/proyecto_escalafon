<?php

namespace App\Console\Commands;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\AuditoriaOperaciones;
use App\Support\Plataforma\VerificacionAprovisionamiento;
use Illuminate\Console\Command;

/**
 * Verifica (solo lectura) que la base de datos y el almacenamiento de una institución estén preparados y, si lo están,
 * la marca como aprovisionada para que el panel pueda activarla. No crea bases, no migra ni escribe en la base
 * institucional: es para una institución preparada a mano (la automática es plataforma:aprovisionar).
 * Comprobaciones: VerificacionAprovisionamiento.
 */
class VerificarAprovisionamiento extends Command
{
    protected $signature = 'plataforma:verificar-aprovisionamiento {slug : Identificador de la institución}';

    protected $description = 'Verifica la base y el almacenamiento de una institución y la marca como aprovisionada';

    public function handle(): int
    {
        $institucion = Institucion::query()->where('slug', $this->argument('slug'))->first();
        if (! $institucion) {
            $this->error('No existe una institución con ese identificador.');

            return self::FAILURE;
        }
        if ($institucion->aprovisionada()) {
            $this->info('La institución ya está aprovisionada.');

            return self::SUCCESS;
        }
        $comprobaciones = ['base_datos' => VerificacionAprovisionamiento::base($institucion->base_datos), 'almacenamiento' => VerificacionAprovisionamiento::almacenamiento($institucion->almacenamiento)];
        foreach ($comprobaciones as $nombre => [$ok, $detalle]) {
            $ok ? $this->info("{$nombre}: {$detalle}") : $this->error("{$nombre}: {$detalle}");
        }
        if (collect($comprobaciones)->contains(fn (array $c): bool => ! $c[0])) {
            $this->error('La institución sigue sin aprovisionar.');

            return self::FAILURE;
        }
        $institucion->update(['aprovisionada_en' => now()]);
        AuditoriaOperaciones::registrar('institucion.aprovisionada', null, null, $institucion->id, null,
            ['base_datos' => $institucion->base_datos, 'almacenamiento' => $institucion->almacenamiento], 'Verificada con plataforma:verificar-aprovisionamiento.');
        $this->info('Institución aprovisionada. Ya se puede activar desde el panel.');

        return self::SUCCESS;
    }
}
