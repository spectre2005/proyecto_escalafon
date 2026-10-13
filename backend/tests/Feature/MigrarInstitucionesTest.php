<?php

namespace Tests\Feature;

use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;
use Throwable;

/**
 * plataforma:migrar-instituciones (cada despliegue) contra el servidor PostgreSQL de Docker: aplica lo pendiente en
 * cada base aprovisionada, sigue con las demás si una falla y lo audita.
 */
class MigrarInstitucionesTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private string $sufijo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sufijo = bin2hex(random_bytes(4));
        config(['database.connections.prueba_servidor' => [...config('database.connections.pgsql'), 'database' => 'postgres']]);
        try {
            DB::connection('prueba_servidor')->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('Necesita el servidor PostgreSQL de Docker: '.$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        DB::purge('prueba_base');
        foreach (DB::connection('prueba_servidor')->select("select datname from pg_database where datname like 'escalafon_migr_{$this->sufijo}%'") as $fila) {
            DB::connection('prueba_servidor')->statement('DROP DATABASE IF EXISTS "'.$fila->datname.'" WITH (FORCE)');
        }
        DB::purge('prueba_servidor');
        parent::tearDown();
    }

    /** Institución aprovisionada con su base migrada salvo la última migración (queda pendiente). */
    private function conPendiente(string $nombre, string $estado = Institucion::ACTIVA): Institucion
    {
        $base = "escalafon_migr_{$this->sufijo}_{$nombre}";
        DB::connection('prueba_servidor')->statement('CREATE DATABASE "'.$base.'"');
        config(['database.connections.prueba_base' => [...config('database.connections.pgsql'), 'database' => $base]]);
        $this->artisan('migrate', ['--database' => 'prueba_base', '--force' => true])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--database' => 'prueba_base', '--step' => 1, '--force' => true])->assertSuccessful();
        DB::purge('prueba_base');

        return $this->institucion(['slug' => "migr-{$this->sufijo}-{$nombre}", 'estado' => $estado, 'base_datos' => $base, 'aprovisionada_en' => now()]);
    }

    private function ultimaMigracion(): string
    {
        $archivos = glob(database_path('migrations/*.php'));
        sort($archivos);

        return basename(end($archivos), '.php');
    }

    public function test_every_provisioned_database_gets_its_pending_migrations(): void
    {
        $activa = $this->conPendiente('a');
        $suspendida = $this->conPendiente('b', Institucion::SUSPENDIDA);
        $sinAprovisionar = $this->institucion(['slug' => "migr-{$this->sufijo}-c", 'base_datos' => "escalafon_migr_{$this->sufijo}_c"]);

        $this->artisan('plataforma:migrar-instituciones')->expectsOutputToContain('2 institución(es) al día.')->assertSuccessful();

        foreach ([$activa, $suspendida] as $institucion) {
            config(['database.connections.prueba_base' => [...config('database.connections.pgsql'), 'database' => $institucion->base_datos]]);
            $this->assertTrue(DB::connection('prueba_base')->table('migrations')->where('migration', $this->ultimaMigracion())->exists());
            DB::purge('prueba_base');
            $this->assertSame([$this->ultimaMigracion()], AuditoriaPlataforma::query()->where('institucion_id', $institucion->id)
                ->where('accion', 'institucion.migrada')->sole()->datos_despues['migraciones']);
        }
        $this->assertSame(0, AuditoriaPlataforma::query()->where('institucion_id', $sinAprovisionar->id)->count(), 'Sin aprovisionar: no tiene base.');

        // Una segunda ejecución no tiene nada que aplicar ni que auditar.
        $this->artisan('plataforma:migrar-instituciones')->expectsOutputToContain('al día')->assertSuccessful();
        $this->assertSame(2, AuditoriaPlataforma::query()->where('accion', 'institucion.migrada')->count());
    }

    public function test_a_failing_institution_does_not_stop_the_others_and_the_command_fails(): void
    {
        $sinBase = $this->institucion(['slug' => "migr-{$this->sufijo}-a", 'estado' => Institucion::ACTIVA,
            'base_datos' => "escalafon_migr_{$this->sufijo}_inexistente", 'aprovisionada_en' => now()]);
        $sana = $this->conPendiente('b');

        $this->artisan('plataforma:migrar-instituciones')->expectsOutputToContain('1 de 2 institución(es) no se migraron')->assertFailed();
        $this->assertSame(1, AuditoriaPlataforma::query()->where('institucion_id', $sinBase->id)->where('accion', 'institucion.migracion_fallida')->count());
        $this->assertSame(1, AuditoriaPlataforma::query()->where('institucion_id', $sana->id)->where('accion', 'institucion.migrada')->count());
        $this->assertNull(DB::connection('prueba_servidor')->selectOne('select 1 from pg_database where datname = ?', [$sinBase->base_datos]),
            'Una base que falta no se crea vacía: eso es del aprovisionamiento.');
    }

    public function test_a_single_institution_can_be_migrated(): void
    {
        $una = $this->conPendiente('a');
        $otra = $this->conPendiente('b');

        $this->artisan('plataforma:migrar-instituciones', ['--institucion' => $una->slug])->assertSuccessful();
        $this->assertSame(1, AuditoriaPlataforma::query()->where('accion', 'institucion.migrada')->where('institucion_id', $una->id)->count());
        $this->assertSame(0, AuditoriaPlataforma::query()->where('institucion_id', $otra->id)->count());
        $this->artisan('plataforma:migrar-instituciones', ['--institucion' => 'no-existe'])->assertFailed();
    }

    public function test_without_a_platform_database_the_single_institution_mode_has_nothing_to_migrate(): void
    {
        config(['plataforma.modo' => 'unica', 'database.connections.plataforma' => [...config('database.connections.pgsql'), 'database' => "no_existe_{$this->sufijo}"]]);
        DB::purge('plataforma');

        $this->artisan('plataforma:migrar-instituciones')->expectsOutputToContain('Sin base de plataforma disponible')->assertSuccessful();

        config(['plataforma.modo' => 'subdominio']);
        $this->artisan('plataforma:migrar-instituciones')->assertFailed();
        $this->assertNull(DB::connection('prueba_servidor')->selectOne('select 1 from pg_database where datname = ?', ["no_existe_{$this->sufijo}"]));
    }
}
