<?php

namespace Tests\Feature;

use App\Models\Plataforma\AdministradorPlataforma;
use App\Models\Plataforma\Institucion;
use Database\Seeders\InstitucionesPruebaSeeder;
use Database\Seeders\SuperadministradorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/**
 * Superadministrador e instituciones de prueba: iguales en todos los equipos, nunca en producción. Las instituciones
 * adicionales se aprovisionan en PostgreSQL y aquí (SQLite) se omiten; se prueba la principal.
 */
class CuentasPruebaSeederTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const EMAIL = 'superadmin@demo.test';

    private const CLAVE = 'Demo-Escalafon-2026';

    protected function setUp(): void
    {
        parent::setUp();
        config(['plataforma.administrador_prueba' => ['email' => ' SUPERADMIN@demo.test ', 'password' => self::CLAVE],
            'plataforma.institucion_prueba' => ['slug' => 'principal', 'nombre' => 'Universidad Principal de Prueba']]);
    }

    public function test_creates_the_account_once_and_it_can_log_in(): void
    {
        $this->seed(SuperadministradorSeeder::class);
        $this->seed(SuperadministradorSeeder::class);

        $this->assertSame(1, AdministradorPlataforma::query()->where('email', self::EMAIL)->count());
        $this->postJson('/api/plataforma/auth/login', ['email' => self::EMAIL, 'password' => self::CLAVE])->assertOk();
    }

    public function test_does_nothing_without_credentials(): void
    {
        config(['plataforma.administrador_prueba' => ['email' => null, 'password' => null],
            'plataforma.institucion_prueba' => ['slug' => null, 'nombre' => null]]);
        $this->baseConNombre('escalafon_prueba');
        $this->seed(SuperadministradorSeeder::class);
        $this->seed(InstitucionesPruebaSeeder::class);

        $this->assertSame(0, AdministradorPlataforma::query()->count());
        $this->assertSame(0, Institucion::query()->count());
    }

    public function test_does_nothing_in_production(): void
    {
        $this->baseConNombre('escalafon_prueba');
        $this->app->instance('env', 'production');
        $this->app->make(SuperadministradorSeeder::class)->run();
        $this->app->make(InstitucionesPruebaSeeder::class)->run();

        $this->assertSame(0, AdministradorPlataforma::query()->count());
        $this->assertSame(0, Institucion::query()->count());
    }

    public function test_main_institution_uses_the_seeded_database_once(): void
    {
        $this->baseConNombre('escalafon_prueba');
        $this->seed(InstitucionesPruebaSeeder::class);
        $this->seed(InstitucionesPruebaSeeder::class);

        $principal = Institucion::query()->sole();
        $this->assertSame(['principal', 'Universidad Principal de Prueba', Institucion::ACTIVA, 'escalafon_prueba', 'instituciones/principal'],
            [$principal->slug, $principal->nombre, $principal->estado, $principal->base_datos, $principal->almacenamiento]);
        $this->assertTrue($principal->aprovisionada() && $principal->operativa());
        $this->assertDirectoryExists(storage_path('app/private/instituciones/principal'), 'Su carpeta de archivos, como en el aprovisionamiento.');
        $this->assertSame('Universidad Principal de Prueba', json_decode(DB::table('institution_settings')->value('settings'), true)['name']);
    }

    public function test_main_institution_respects_a_database_already_linked(): void
    {
        $this->baseConNombre('escalafon_prueba');
        Institucion::query()->create(['nombre' => 'Otra', 'slug' => 'otra', 'estado' => Institucion::ACTIVA,
            'base_datos' => 'escalafon_prueba', 'almacenamiento' => 'instituciones/otra', 'aprovisionada_en' => now()]);
        DB::table('institution_settings')->insert(['id' => 1, 'settings' => json_encode(['name' => 'Otra']), 'created_at' => now(), 'updated_at' => now()]);
        $this->seed(InstitucionesPruebaSeeder::class);

        $this->assertSame(['otra'], Institucion::query()->pluck('slug')->all());
        $this->assertSame('Otra', json_decode(DB::table('institution_settings')->value('settings'), true)['name']);
    }

    public function test_main_institution_is_skipped_for_a_database_without_a_valid_name(): void
    {
        // En los tests la base es SQLite en memoria («:memory:»), que no puede ser de una institución.
        $this->seed(InstitucionesPruebaSeeder::class);

        $this->assertSame(0, Institucion::query()->count());
    }

    /** Simula el nombre de la base sembrada (en PostgreSQL, p. ej. «escalafon») sin cambiar de motor. */
    private function baseConNombre(string $nombre): void
    {
        $conexion = $this->app['db']->connection();
        $conexion->setDatabaseName($nombre);
        $this->beforeApplicationDestroyed(fn () => $conexion->setDatabaseName(':memory:'));
    }
}
