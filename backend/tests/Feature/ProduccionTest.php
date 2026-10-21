<?php

namespace Tests\Feature;

use App\Models\Plataforma\AdministradorPlataforma;
use App\Models\Plataforma\Institucion;
use App\Models\Usuario;
use Database\Seeders\AdministradorSeeder;
use Database\Seeders\InstitucionesPruebaSeeder;
use Database\Seeders\SuperadministradorSeeder;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/**
 * Producción: la API solo responde fuera de local y pruebas si se habilitó (ApiHabilitada), y produccion:verificar
 * rechaza una configuración insegura (el contenedor no arranca) y acepta una segura. Caddy solo pide certificados HTTPS
 * para el dominio de la plataforma y los subdominios de instituciones registradas. PLATAFORMA_ALMACENAMIENTO_UNICA se
 * revisa según el modo, con carpetas en el storage temporal de PHPUnit.
 */
class ProduccionTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    /** Configuración segura de producción con subdominios. */
    private function segura(array $cambios = []): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config([
            'app.debug' => false, 'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.url' => 'https://escalafon.pe',
            'app.api_habilitada' => true, 'session.secure' => true, 'session.http_only' => true, 'session.domain' => null,
            'database.connections.pgsql.password' => 'clave-larga-de-produccion-1', 'database.connections.plataforma.password' => 'otra-clave-larga-produccion-2',
            'plataforma.modo' => 'subdominio', 'plataforma.dominio' => 'escalafon.pe', 'sanctum.stateful' => ['escalafon.pe', '*.escalafon.pe'],
            'logging.default' => 'stderr', 'logging.channels.stderr.level' => 'warning',
            // Vacías aunque el .env local (copiado de .env.example) traiga las cuentas de prueba.
            'auth.administrador_inicial' => ['email' => null, 'password' => null],
            'plataforma.administrador_prueba' => ['email' => null, 'password' => null],
            'plataforma.institucion_prueba' => ['slug' => null, 'nombre' => null],
            'identidad.proveedor' => 'ninguno',
            ...$cambios,
        ]);
    }

    public function test_a_safe_configuration_passes(): void
    {
        $this->segura();

        $this->artisan('produccion:verificar')->expectsOutput('Configuración de producción verificada.')->assertSuccessful();
    }

    public function test_an_unsafe_configuration_does_not_start(): void
    {
        foreach ([
            ['app.debug' => true], ['app.url' => 'http://escalafon.pe'], ['session.secure' => null], ['app.key' => ''],
            ['database.connections.pgsql.password' => 'secret'], ['database.connections.plataforma.password' => 'corta'],
            ['plataforma.dominio' => 'localhost'], ['session.domain' => '.escalafon.pe'], ['sanctum.stateful' => ['escalafon.pe']],
        ] as $cambio) {
            $this->segura($cambio);
            $this->artisan('produccion:verificar')->assertFailed();
        }
        $this->app->detectEnvironment(fn (): string => 'staging');
        $this->artisan('produccion:verificar')->expectsOutputToContain('APP_ENV debe ser production')->assertFailed();
    }

    public function test_the_demo_accounts_of_env_example_do_not_start(): void
    {
        foreach ([
            'auth.administrador_inicial.email' => 'ADMIN_EMAIL', 'auth.administrador_inicial.password' => 'ADMIN_PASSWORD',
            'plataforma.administrador_prueba.email' => 'PLATAFORMA_ADMIN_EMAIL', 'plataforma.administrador_prueba.password' => 'PLATAFORMA_ADMIN_PASSWORD',
            'plataforma.institucion_prueba.slug' => 'PLATAFORMA_PRUEBA_SLUG', 'plataforma.institucion_prueba.nombre' => 'PLATAFORMA_PRUEBA_NOMBRE',
        ] as $clave => $variable) {
            $this->segura([$clave => 'valor-de-env-example']);
            $this->artisan('produccion:verificar')->expectsOutputToContain("{$variable} debe quedar vacía")->assertFailed();
        }
    }

    public function test_the_simulated_identity_provider_does_not_start(): void
    {
        $this->segura(['identidad.proveedor' => 'simulado']);
        $this->artisan('produccion:verificar')->expectsOutputToContain('IDENTIDAD_PROVEEDOR no puede ser simulado')->assertFailed();
    }

    public function test_factiliza_needs_its_token_and_is_reported_as_external(): void
    {
        $this->segura(['identidad.proveedor' => 'factiliza', 'identidad.factiliza.token' => '']);
        $this->artisan('produccion:verificar')->expectsOutputToContain('IDENTIDAD_PROVEEDOR=factiliza exige FACTILIZA_TOKEN')->assertFailed();

        $this->segura(['identidad.proveedor' => 'factiliza', 'identidad.factiliza.token' => 'token-de-prueba']);
        $this->artisan('produccion:verificar')->expectsOutputToContain('servicio externo de pago, no oficial')
            ->doesntExpectOutputToContain('exige FACTILIZA_TOKEN')->assertSuccessful();
    }

    public function test_the_demo_seeders_create_nothing_in_production(): void
    {
        $this->segura([
            'auth.administrador_inicial' => ['email' => 'admin.uniq@demo.test', 'password' => 'Demo-Escalafon-2026'],
            'plataforma.administrador_prueba' => ['email' => 'superadmin@demo.test', 'password' => 'Demo-Escalafon-2026'],
            'plataforma.institucion_prueba' => ['slug' => 'uniq', 'nombre' => 'Institución principal de prueba'],
        ]);
        DB::connection()->setDatabaseName('escalafon');
        try {
            foreach ([AdministradorSeeder::class, SuperadministradorSeeder::class, InstitucionesPruebaSeeder::class, UsuarioSeeder::class] as $seeder) {
                $this->app->make($seeder)->run();
            }
        } finally {
            DB::connection()->setDatabaseName(':memory:');
        }

        $this->assertSame(0, Usuario::query()->count());
        $this->assertSame(0, AdministradorPlataforma::query()->count());
        $this->assertSame(0, Institucion::query()->count());
    }

    public function test_outside_local_the_api_answers_only_when_enabled(): void
    {
        // Modo unica: con subdominio, localhost respondería 404 antes (sin institución).
        $this->segura(['app.api_habilitada' => false, 'plataforma.modo' => 'unica']);
        $this->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('message', 'La API no está habilitada en este entorno.');
        $this->getJson('/api/plataforma/auth/me')->assertForbidden();

        config(['app.api_habilitada' => true]);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        // CRUD genérico: se puede leer (con sesión), pero no escribir salvo que se abra expresamente.
        $this->getJson('/api/v1/feriados')->assertUnauthorized();
        foreach (['postJson' => '/api/v1/feriados', 'deleteJson' => '/api/v1/auditoria-eventos/'.Str::uuid(), 'patchJson' => '/api/v1/legajos/'.Str::uuid()] as $metodo => $url) {
            $this->{$metodo}($url)->assertForbidden()->assertJsonPath('message', 'En este entorno, el acceso genérico por tabla es de solo lectura. Usa las pantallas de cada sección.');
        }
        config(['app.crud_generico_escritura' => true]);
        $this->postJson('/api/v1/feriados')->assertUnauthorized();
        // Las rutas de cada sección (con sus reglas) no se ven afectadas.
        config(['app.crud_generico_escritura' => false]);
        $this->postJson('/api/v1/usuarios/con-vinculo')->assertUnauthorized();
    }

    public function test_https_certificates_are_only_requested_for_known_hosts(): void
    {
        config(['plataforma.modo' => 'subdominio', 'plataforma.dominio' => 'escalafon.pe']);
        $this->institucion(['slug' => 'uniq']);

        foreach (['escalafon.pe' => 200, 'uniq.escalafon.pe' => 200, 'UNIQ.escalafon.pe' => 200, 'otra.escalafon.pe' => 404,
            'a.uniq.escalafon.pe' => 404, 'uniq.escalafon.pe.malo.com' => 404, 'malo.com' => 404, '' => 404] as $host => $estado) {
            // Caddy llega por su red interna, con cualquier host: la ruta no pertenece a ninguna institución.
            $this->get('http://caddy/interno/tls?domain='.urlencode($host))->assertStatus($estado);
        }
        $this->get('http://caddy/interno/tls?domain=uniq.escalafon.pe')->assertCookieMissing(config('session.cookie'));

        config(['plataforma.modo' => 'unica', 'app.url' => 'https://rrhh.universidad.edu.pe']);
        $this->get('http://caddy/interno/tls?domain=rrhh.universidad.edu.pe')->assertOk();
        $this->get('http://caddy/interno/tls?domain=uniq.escalafon.pe')->assertNotFound();
    }

    protected function tearDown(): void
    {
        // Solo el storage temporal de PHPUnit (tests/bootstrap.php). Primero los enlaces: deleteDirectory no sigue su destino.
        if (str_starts_with(storage_path(), sys_get_temp_dir().'/escalafon-pruebas-')) {
            File::deleteDirectory(storage_path('app/private/instituciones'));
            File::deleteDirectory(storage_path('app/private/documentos'));
        }
        parent::tearDown();
    }

    /** Institución de esta base (la conexión por defecto) con su carpeta: real con un PDF, o con un enlace a la común. */
    private function carpetaUnica(bool $normalizada): void
    {
        if (! str_starts_with(storage_path(), sys_get_temp_dir().'/escalafon-pruebas-')) {
            throw new RuntimeException('Esta prueba solo corre en el storage temporal de PHPUnit.');
        }
        $this->institucion(['slug' => 'uniq', 'estado' => Institucion::ACTIVA, 'aprovisionada_en' => now(),
            'base_datos' => (string) config('database.connections.'.config('database.default').'.database')]);
        $privado = storage_path('app/private');
        File::ensureDirectoryExists($privado.'/instituciones/uniq');
        File::ensureDirectoryExists($privado.'/documentos');
        if ($normalizada) {
            File::ensureDirectoryExists($privado.'/instituciones/uniq/documentos');
            file_put_contents($privado.'/instituciones/uniq/documentos/a.pdf', 'pdf');
        } else {
            symlink('../../documentos', $privado.'/instituciones/uniq/documentos');
        }
    }

    /** @return array{int, string} código de salida y salida completa de produccion:verificar */
    private function verificar(): array
    {
        $codigo = Artisan::call('produccion:verificar');

        return [$codigo, Artisan::output()];
    }

    public function test_with_subdomains_the_single_mode_storage_variable_is_only_a_warning(): void
    {
        $this->segura(['plataforma.almacenamiento_unica' => 'instituciones/uniq']);
        [$codigo, $salida] = $this->verificar();
        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('Aviso: PLATAFORMA_ALMACENAMIENTO_UNICA solo se usa con PLATAFORMA_MODO=unica', $salida);
        $this->assertStringContainsString('Configuración de producción verificada.', $salida);

        $this->segura(['plataforma.almacenamiento_unica' => '']);
        [$codigo, $salida] = $this->verificar();
        $this->assertSame(0, $codigo);
        $this->assertStringNotContainsString('PLATAFORMA_ALMACENAMIENTO_UNICA', $salida);
    }

    public function test_single_mode_accepts_the_storage_variable_of_a_normalized_folder(): void
    {
        $this->carpetaUnica(normalizada: true);
        $this->segura(['plataforma.modo' => 'unica', 'plataforma.almacenamiento_unica' => 'instituciones/uniq']);

        [$codigo, $salida] = $this->verificar();
        $this->assertSame(0, $codigo);
        $this->assertStringNotContainsString('PLATAFORMA_ALMACENAMIENTO_UNICA', $salida);
        $this->assertStringContainsString('Configuración de producción verificada.', $salida);
    }

    public function test_single_mode_rejects_a_storage_variable_that_does_not_fit(): void
    {
        $this->carpetaUnica(normalizada: false);
        $this->institucion(['slug' => 'otra', 'estado' => Institucion::ACTIVA, 'aprovisionada_en' => now()]);
        File::ensureDirectoryExists(storage_path('app/private/instituciones/otra'));
        foreach (['instituciones/uniq' => 'la carpeta instituciones/uniq tiene enlaces simbólicos', 'instituciones/otra' => 'su base no es la de esta instalación',
            '../documentos' => 'no es una carpeta institucional válida', 'instituciones/nadie' => 'ninguna institución de la plataforma'] as $valor => $motivo) {
            $this->segura(['plataforma.modo' => 'unica', 'plataforma.almacenamiento_unica' => $valor]);

            [$codigo, $salida] = $this->verificar();
            $this->assertSame(1, $codigo, $valor);
            $this->assertMatchesRegularExpression('/^Error: PLATAFORMA_ALMACENAMIENTO_UNICA: .*'.preg_quote($motivo, '/').'/m', $salida);
        }
    }

    public function test_single_mode_warns_when_the_folder_is_normalized_but_the_variable_is_empty(): void
    {
        $this->carpetaUnica(normalizada: true);
        $this->segura(['plataforma.modo' => 'unica', 'plataforma.almacenamiento_unica' => '']);

        [$codigo, $salida] = $this->verificar();
        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('Aviso: La carpeta instituciones/uniq de la institución de esta base ya es una carpeta real con archivos, pero PLATAFORMA_ALMACENAMIENTO_UNICA está vacía', $salida);
        $this->assertStringContainsString('añade PLATAFORMA_ALMACENAMIENTO_UNICA=instituciones/uniq; si sus archivos siguen en las carpetas comunes, no la añadas.', $salida);
    }

    public function test_single_mode_says_nothing_before_normalizing_or_without_platform(): void
    {
        // Con enlaces (UNIQ hoy) o con la carpeta vacía: la variable no debe añadirse todavía.
        $this->carpetaUnica(normalizada: false);
        $this->segura(['plataforma.modo' => 'unica', 'plataforma.almacenamiento_unica' => '']);
        [$codigo, $salida] = $this->verificar();
        $this->assertSame(0, $codigo);
        $this->assertStringNotContainsString('PLATAFORMA_ALMACENAMIENTO_UNICA', $salida);

        File::deleteDirectory(storage_path('app/private/instituciones/uniq'));
        File::ensureDirectoryExists(storage_path('app/private/instituciones/uniq'));
        $this->assertStringNotContainsString('PLATAFORMA_ALMACENAMIENTO_UNICA', $this->verificar()[1]);

        // Normalizada, pero sin base de plataforma legible (instalación unica antigua): ni aviso ni error.
        File::ensureDirectoryExists(storage_path('app/private/instituciones/uniq/documentos'));
        file_put_contents(storage_path('app/private/instituciones/uniq/documentos/a.pdf'), 'pdf');
        $this->assertStringContainsString('PLATAFORMA_ALMACENAMIENTO_UNICA está vacía', $this->verificar()[1]);
        config(['database.connections.plataforma.database' => storage_path('app/private/no-existe/plataforma.sqlite')]);
        DB::purge('plataforma');
        [$codigo, $salida] = $this->verificar();
        $this->assertSame(0, $codigo);
        $this->assertStringNotContainsString('PLATAFORMA_ALMACENAMIENTO_UNICA', $salida);
    }
}
