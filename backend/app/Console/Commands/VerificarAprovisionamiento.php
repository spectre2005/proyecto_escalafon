<?php

namespace App\Console\Commands;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\AuditoriaOperaciones;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Verifica (solo lectura) que la base de datos y el almacenamiento de una institución estén preparados y, si lo están,
 * la marca como aprovisionada para que el panel pueda activarla. No crea bases, no migra ni escribe en la base
 * institucional: el aprovisionamiento automático es una fase posterior.
 *
 * - Base: existe en el servidor PostgreSQL institucional y tiene el esquema migrado (migrations e institution_settings).
 * - Almacenamiento: la carpeta storage/app/private/{almacenamiento} existe y se puede escribir.
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
        $comprobaciones = ['base_datos' => $this->baseLista($institucion->base_datos), 'almacenamiento' => $this->almacenamientoListo($institucion->almacenamiento)];
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

    /** @return array{bool, string} */
    private function baseLista(string $base): array
    {
        $plantilla = config('database.connections.pgsql');
        if (config('database.default') !== 'pgsql' || ! is_array($plantilla)) {
            return [false, 'solo se verifica contra el servidor PostgreSQL institucional.'];
        }
        config(['database.connections.verificacion_institucion' => [...$plantilla, 'database' => $base]]);
        try {
            $listo = Schema::connection('verificacion_institucion')->hasTable('migrations') && Schema::connection('verificacion_institucion')->hasTable('institution_settings');

            return $listo ? [true, "la base {$base} existe y tiene el esquema institucional."] : [false, "la base {$base} existe pero no tiene el esquema institucional migrado."];
        } catch (Throwable) {
            return [false, "no se pudo conectar a la base {$base} (¿existe?)."];
        } finally {
            DB::purge('verificacion_institucion');
        }
    }

    /** @return array{bool, string} */
    private function almacenamientoListo(string $ruta): array
    {
        $carpeta = storage_path('app/private/'.$ruta);

        return is_dir($carpeta) && is_writable($carpeta) ? [true, "la carpeta {$ruta} existe y se puede escribir."] : [false, "falta la carpeta {$ruta} o no se puede escribir."];
    }
}
