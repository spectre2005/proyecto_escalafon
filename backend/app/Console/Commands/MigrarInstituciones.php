<?php

namespace App\Console\Commands;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\AuditoriaOperaciones;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Migraciones de todas las bases en cada despliegue, después del «migrate» normal (que solo migra la base del .env):
 * 1. la base de la plataforma (database/migrations/plataforma);
 * 2. la base de cada institución aprovisionada, también las suspendidas (vuelven con el esquema al día). Las registradas
 *    sin aprovisionar no tienen base todavía.
 *
 * Si una institución falla se sigue con las demás y el comando termina con error. Queda auditado lo aplicado a cada
 * una (institucion.migrada) y cada fallo (institucion.migracion_fallida). Nunca crea una base que falte: eso es del
 * aprovisionamiento. Sin base de plataforma en el modo «unica» (desarrollo con una sola institución) no hay nada que
 * migrar y no es un error.
 *
 * Con --crear-base (instalación local: iniciar.ps1 y README) crea antes la base de la plataforma si falta, para que un
 * equipo recién clonado no dependa de crearla a mano. En producción la crea docker/produccion/initdb.
 */
class MigrarInstituciones extends Command
{
    private const CONEXION = 'migracion_institucion';

    private const SERVIDOR = 'migracion_servidor';

    protected $signature = 'plataforma:migrar-instituciones {--institucion= : Solo la institución con este identificador} {--crear-base : Crea la base de la plataforma si falta (instalación local)}';

    protected $description = 'Migra la base de la plataforma y la de cada institución aprovisionada (ejecutar en cada despliegue)';

    public function handle(): int
    {
        try {
            if ($this->option('crear-base')) {
                $this->crearBasePlataforma();
            }
            $this->migrar('plataforma', ['--path' => 'database/migrations/plataforma']);
        } catch (Throwable $error) {
            if (config('plataforma.modo') === 'unica' && ! $this->option('institucion')) {
                $this->warn('Sin base de plataforma disponible: con una sola institución no hay otras bases que migrar.');

                return self::SUCCESS;
            }
            $this->error('No se pudo migrar la base de la plataforma: '.$error->getMessage());

            return self::FAILURE;
        }
        $this->info('Base de la plataforma al día.');

        $instituciones = Institucion::query()->whereNotNull('aprovisionada_en')->orderBy('slug')
            ->when($this->option('institucion'), fn ($query, string $slug) => $query->where('slug', $slug))->get();
        if ($instituciones->isEmpty()) {
            $this->info($this->option('institucion') ? 'No hay una institución aprovisionada con ese identificador.' : 'No hay instituciones aprovisionadas.');

            return $this->option('institucion') ? self::FAILURE : self::SUCCESS;
        }

        $fallidas = 0;
        foreach ($instituciones as $institucion) {
            try {
                $aplicadas = $this->migrarInstitucion($institucion);
                $this->line(sprintf('  %s (%s): %s', $institucion->slug, $institucion->base_datos,
                    $aplicadas === [] ? 'al día' : count($aplicadas).' migración(es) aplicada(s)'));
                if ($aplicadas !== []) {
                    AuditoriaOperaciones::registrar('institucion.migrada', null, null, $institucion->id, null, ['migraciones' => $aplicadas], 'Migración en despliegue.');
                }
            } catch (Throwable $error) {
                $fallidas++;
                $this->error(sprintf('  %s (%s): falló. %s', $institucion->slug, $institucion->base_datos, $error->getMessage()));
                AuditoriaOperaciones::registrar('institucion.migracion_fallida', null, null, $institucion->id, null, null,
                    mb_substr('Migración en despliegue. Error: '.$error->getMessage(), 0, 500));
            }
        }
        if ($fallidas > 0) {
            $this->error("{$fallidas} de {$instituciones->count()} institución(es) no se migraron. Revisa el error y vuelve a ejecutar el comando.");

            return self::FAILURE;
        }
        $this->info("{$instituciones->count()} institución(es) al día.");

        return self::SUCCESS;
    }

    /** @return list<string> migraciones aplicadas en esta ejecución */
    private function migrarInstitucion(Institucion $institucion): array
    {
        // Mismo servidor PostgreSQL institucional (conexión «pgsql»); solo cambia la base.
        config(['database.connections.'.self::CONEXION => [...config('database.connections.pgsql'), 'database' => $institucion->base_datos]]);
        try {
            $antes = $this->aplicadas();
            $this->migrar(self::CONEXION);

            return array_values(array_diff($this->aplicadas(), $antes));
        } finally {
            DB::purge(self::CONEXION);
        }
    }

    /** Solo PostgreSQL; el nombre se valida antes de ir entre comillas dobles. */
    private function crearBasePlataforma(): void
    {
        $conexion = (array) config('database.connections.plataforma');
        $base = (string) ($conexion['database'] ?? '');
        if (($conexion['driver'] ?? null) !== 'pgsql' || ! preg_match(Institucion::PATRON_BASE_DATOS, $base)) {
            return;
        }
        config(['database.connections.'.self::SERVIDOR => [...$conexion, 'database' => 'postgres']]);
        try {
            if (DB::connection(self::SERVIDOR)->selectOne('select 1 from pg_database where datname = ?', [$base]) === null) {
                DB::connection(self::SERVIDOR)->statement('CREATE DATABASE "'.$base.'"');
                $this->info("Base de la plataforma {$base} creada.");
            }
        } finally {
            DB::purge(self::SERVIDOR);
        }
    }

    /** @return list<string> */
    private function aplicadas(): array
    {
        return Schema::connection(self::CONEXION)->hasTable('migrations')
            ? DB::connection(self::CONEXION)->table('migrations')->pluck('migration')->all() : [];
    }

    /**
     * Solo migra una base que ya existe: conectarse antes evita que «migrate --force» cree una base vacía (sin catálogos
     * ni administrador) cuando falta la de una institución.
     *
     * @param  array<string, mixed>  $parametros
     */
    private function migrar(string $conexion, array $parametros = []): void
    {
        DB::connection($conexion)->getPdo();
        if (Artisan::call('migrate', ['--database' => $conexion, '--force' => true, ...$parametros]) !== 0) {
            throw new RuntimeException(mb_substr(trim(Artisan::output()), -300));
        }
    }
}
