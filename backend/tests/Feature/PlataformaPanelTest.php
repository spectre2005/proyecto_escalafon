<?php

namespace Tests\Feature;

use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use App\Models\Usuario;
use Database\Seeders\AdministradorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;
use Throwable;

/**
 * Panel de la plataforma SaaS: autenticación separada (guard plataforma frente a la sesión institucional), instituciones
 * con sus estados y transiciones, auditoría y ausencia de rutas a datos institucionales.
 */
class PlataformaPanelTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private function entrar(): void
    {
        $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => 'SUPER@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        foreach (['/api/plataforma/auth/me', '/api/plataforma/dashboard', '/api/plataforma/instituciones'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->postJson('/api/plataforma/instituciones', ['nombre' => 'X', 'slug' => 'x-y'])->assertUnauthorized();
    }

    public function test_login_and_logout_of_the_platform_administrator(): void
    {
        $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'otra-clave'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk()
            ->assertJsonPath('data.administrador.email', 'super@example.test')->assertJsonMissingPath('data.administrador.password');
        $this->getJson('/api/plataforma/auth/me')->assertOk();
        $this->postJson('/api/plataforma/auth/logout')->assertOk();
        $this->assertGuest('plataforma');
        $this->assertSame(['sesion.inicio', 'sesion.cierre'], AuditoriaPlataforma::query()->orderBy('created_at')->orderBy('accion', 'desc')->pluck('accion')->all());
    }

    public function test_login_requires_the_csrf_token_outside_tests(): void
    {
        $this->administrador();
        $this->app->instance('env', 'local');
        $datos = ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'];
        $this->postJson('/api/plataforma/auth/login', $datos)->assertStatus(419);
        // El token se pide a la plataforma (su sesión, su cookie) y viaja en X-CSRF-TOKEN.
        $token = $this->getJson('/api/plataforma/auth/csrf')->assertOk()->json('data.token');
        $this->assertIsString($token);
        $this->withHeader('X-CSRF-TOKEN', 'otro-token')->postJson('/api/plataforma/auth/login', $datos)->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', $token)->postJson('/api/plataforma/auth/login', $datos)->assertOk();
    }

    /**
     * Con sesiones reales (archivos en una carpeta temporal y cookies entre peticiones, sin el almacén en memoria de
     * las pruebas): la plataforma usa su cookie y su path, y cerrar sesión ahí no cierra la institucional.
     */
    public function test_the_platform_session_cookie_is_separate_from_the_institutional_one(): void
    {
        $carpeta = storage_path('framework/testing/sesiones-plataforma-'.bin2hex(random_bytes(4)));
        mkdir($carpeta, 0755, true);
        try {
            config(['session.driver' => 'file', 'session.files' => $carpeta,
                'auth.administrador_inicial' => ['email' => 'admin@example.test', 'password' => 'clave-de-prueba-1']]);
            $this->seed(AdministradorSeeder::class);
            $this->administrador();
            $institucional = config('session.cookie');
            $plataforma = config('plataforma.sesion.cookie');
            $this->assertNotSame($institucional, $plataforma);
            $peticion = function (string $metodo, string $url, array $cookies, array $datos = []) {
                // Como un proceso nuevo por petición: nada de la sesión anterior queda en memoria.
                $this->app['session']->forgetDrivers();
                $this->app->forgetInstance('session.store');
                $this->defaultCookies = [];
                Auth::forgetGuards();

                return $this->withCredentials()->withCookies($cookies)->json($metodo, $url, $datos);
            };

            $login = $peticion('POST', '/api/v1/auth/login', [], ['email' => 'admin@example.test', 'password' => 'clave-de-prueba-1'])->assertOk();
            $cookiesInst = [$institucional => $login->getCookie($institucional)->getValue()];

            $entrada = $peticion('POST', '/api/plataforma/auth/login', $cookiesInst, ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
            $cookieP = $entrada->getCookie($plataforma);
            $this->assertNotNull($cookieP);
            $this->assertSame('/api/plataforma', $cookieP->getPath());
            $this->assertNull($entrada->getCookie($institucional, false), 'La plataforma no reescribe la cookie institucional.');
            $ambas = [...$cookiesInst, $plataforma => $cookieP->getValue()];

            // Cada sesión se lee con su cookie: la institucional no abre la plataforma.
            $peticion('GET', '/api/plataforma/auth/me', $cookiesInst)->assertUnauthorized();
            $peticion('GET', '/api/plataforma/auth/me', $ambas)->assertOk()->assertJsonPath('data.administrador.email', 'super@example.test');

            $salida = $peticion('POST', '/api/plataforma/auth/logout', $ambas)->assertOk();
            $this->assertNull($salida->getCookie($institucional, false));
            $peticion('GET', '/api/plataforma/auth/me', $ambas)->assertUnauthorized();
            // La sesión institucional sigue abierta.
            $peticion('GET', '/api/v1/auth/me', $cookiesInst)->assertOk()->assertJsonPath('data.usuario.email_institucional', 'admin@example.test');
        } finally {
            File::deleteDirectory($carpeta);
        }
    }

    public function test_inactive_accounts_and_repeated_failures_are_rejected(): void
    {
        $this->administrador(['activo' => false]);
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertUnprocessable();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'mala']);
        }
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'mala'])->assertStatus(429);
    }

    public function test_an_institutional_account_cannot_use_the_platform(): void
    {
        $this->actuarComo();
        $this->getJson('/api/plataforma/dashboard')->assertUnauthorized();
        $this->getJson('/api/plataforma/instituciones')->assertUnauthorized();
        // Ni con el correo y la contraseña institucionales: son cuentas de otra tabla.
        $usuario = Usuario::factory()->create(['email_institucional' => 'admin.inst@example.test', 'password' => 'Clave-segura-2026']);
        $this->postJson('/api/plataforma/auth/login', ['email' => $usuario->email_institucional, 'password' => 'Clave-segura-2026'])->assertUnprocessable();
    }

    public function test_a_platform_account_cannot_use_the_institutional_routes(): void
    {
        $this->entrar();
        foreach (['/api/v1/auth/me', '/api/v1/usuarios', '/api/v1/expedientes', '/api/v1/institucion'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_institutions_are_listed_created_edited_suspended_and_reactivated(): void
    {
        $this->entrar();
        $creada = $this->postJson('/api/plataforma/instituciones', ['nombre' => '  Universidad de Prueba ', 'slug' => 'Univ-Prueba'])->assertCreated()
            ->assertJsonPath('data.slug', 'univ-prueba')->assertJsonPath('data.nombre', 'Universidad de Prueba')->assertJsonPath('data.estado', 'registrada')
            ->assertJsonPath('data.base_datos', 'escalafon_univ_prueba')->assertJsonPath('data.almacenamiento', 'instituciones/univ-prueba')
            ->assertJsonPath('data.aprovisionada', false)->json('data.id');
        $this->institucion(['nombre' => 'Otra entidad', 'slug' => 'otra']);
        $this->getJson('/api/plataforma/instituciones?q=prueba')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/plataforma/instituciones?estado=registrada')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/plataforma/dashboard')->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.por_estado.registrada', 2);

        $this->patchJson("/api/plataforma/instituciones/{$creada}", ['nombre' => 'Universidad Renombrada', 'base_datos' => 'escalafon_renombrada'])->assertOk()
            ->assertJsonPath('data.base_datos', 'escalafon_renombrada');
        // Sin aprovisionar no se activa (ni se suspende, porque no opera).
        $this->postJson("/api/plataforma/instituciones/{$creada}/activar")->assertConflict();
        $this->postJson("/api/plataforma/instituciones/{$creada}/suspender", ['motivo' => 'Prueba de suspensión'])->assertConflict();
        $this->assertSame('registrada', Institucion::query()->find($creada)->estado);

        // Aprovisionada (lo marca el comando de verificación): se activa, se suspende con motivo y se reactiva.
        Institucion::query()->whereKey($creada)->update(['aprovisionada_en' => now()]);
        $this->postJson("/api/plataforma/instituciones/{$creada}/activar")->assertOk()->assertJsonPath('data.estado', 'activa');
        $this->postJson("/api/plataforma/instituciones/{$creada}/suspender")->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->postJson("/api/plataforma/instituciones/{$creada}/suspender", ['motivo' => 'Contrato vencido'])->assertOk()
            ->assertJsonPath('data.estado', 'suspendida')->assertJsonPath('data.motivo_suspension', 'Contrato vencido');
        $this->postJson("/api/plataforma/instituciones/{$creada}/activar")->assertOk()->assertJsonPath('data.estado', 'activa')->assertJsonPath('data.motivo_suspension', null);
        // Aprovisionada: su ubicación ya no se cambia desde el panel.
        $this->patchJson("/api/plataforma/instituciones/{$creada}", ['slug' => 'otro-slug'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->getJson("/api/plataforma/instituciones/{$creada}")->assertOk()->assertJsonPath('data.slug', 'univ-prueba');
    }

    public function test_duplicate_reserved_and_malformed_identifiers_are_rejected(): void
    {
        $this->entrar();
        $this->institucion(['slug' => 'repetida']);
        $this->postJson('/api/plataforma/instituciones', ['nombre' => 'Duplicada', 'slug' => 'REPETIDA'])->assertUnprocessable()->assertJsonValidationErrors(['slug', 'base_datos', 'almacenamiento']);
        $this->postJson('/api/plataforma/instituciones', ['nombre' => 'Reservada', 'slug' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson('/api/plataforma/instituciones', ['nombre' => 'Mal', 'slug' => '-mal_slug-'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson('/api/plataforma/instituciones', ['nombre' => 'Base mal', 'slug' => 'base-mal', 'base_datos' => 'Base;DROP'])->assertUnprocessable()->assertJsonValidationErrors('base_datos');
        // El estado y la aprovisión no se fijan por la petición.
        $this->postJson('/api/plataforma/instituciones', ['nombre' => 'Atajo', 'slug' => 'atajo', 'estado' => 'activa', 'aprovisionada_en' => now()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors(['estado', 'aprovisionada_en']);
        $this->assertSame(1, Institucion::query()->count());
    }

    public function test_suspension_keeps_the_institution_data(): void
    {
        $this->entrar();
        $institucion = $this->institucion(['slug' => 'conserva', 'estado' => Institucion::ACTIVA, 'aprovisionada_en' => now()]);
        $antes = $institucion->only(['nombre', 'slug', 'base_datos', 'almacenamiento']);
        $this->postJson("/api/plataforma/instituciones/{$institucion->id}/suspender", ['motivo' => 'Mantenimiento programado'])->assertOk();
        $institucion->refresh();
        $this->assertSame($antes, $institucion->only(['nombre', 'slug', 'base_datos', 'almacenamiento']));
        $this->assertNotNull($institucion->aprovisionada_en);
        $this->assertSame(1, Institucion::query()->count());
    }

    /** Un estado no reconocido (futuro o escrito a mano) no se activa ni se suspende, y no deja auditoría. */
    public function test_an_unknown_state_cannot_be_activated_or_suspended(): void
    {
        $this->entrar();
        foreach (['archivada', 'desconocido'] as $estado) {
            $institucion = $this->institucion(['slug' => "estado-{$estado}", 'estado' => $estado, 'aprovisionada_en' => now()]);
            $this->assertFalse($institucion->operativa());
            $this->postJson("/api/plataforma/instituciones/{$institucion->id}/activar")->assertConflict()
                ->assertJsonPath('message', 'La institución tiene un estado no reconocido: no se puede activar.');
            $this->postJson("/api/plataforma/instituciones/{$institucion->id}/suspender", ['motivo' => 'Prueba de estado'])->assertConflict()
                ->assertJsonPath('message', 'La institución tiene un estado no reconocido: no se puede suspender.');
            $this->assertSame($estado, $institucion->refresh()->estado);
            $this->assertSame(0, AuditoriaPlataforma::query()->where('institucion_id', $institucion->id)->count());
        }
    }

    public function test_administrative_operations_are_audited(): void
    {
        $this->entrar();
        $id = $this->postJson('/api/plataforma/instituciones', ['nombre' => 'Auditada', 'slug' => 'auditada'])->json('data.id');
        $this->patchJson("/api/plataforma/instituciones/{$id}", ['nombre' => 'Auditada II'])->assertOk();
        Institucion::query()->whereKey($id)->update(['aprovisionada_en' => now()]);
        $this->postJson("/api/plataforma/instituciones/{$id}/activar")->assertOk();
        $this->postJson("/api/plataforma/instituciones/{$id}/suspender", ['motivo' => 'Revisión de pagos'])->assertOk();
        $eventos = AuditoriaPlataforma::query()->where('institucion_id', $id)->get()->keyBy('accion');
        $this->assertEqualsCanonicalizing(['institucion.registrada', 'institucion.editada', 'institucion.activada', 'institucion.suspendida'], $eventos->keys()->all());
        $this->assertSame(['nombre' => 'Auditada'], array_intersect_key($eventos['institucion.editada']->datos_antes, ['nombre' => 1]));
        $this->assertSame('Revisión de pagos', $eventos['institucion.suspendida']->motivo);
        $this->assertNotNull($eventos['institucion.suspendida']->administrador_id);
        $this->getJson("/api/plataforma/instituciones/{$id}")->assertOk()->assertJsonCount(4, 'data.auditoria');
    }

    public function test_the_platform_exposes_no_institutional_data_routes(): void
    {
        $rutas = collect(Route::getRoutes()->getRoutes())->map->uri()->filter(fn (string $uri): bool => str_starts_with($uri, 'api/plataforma'));
        $this->assertNotEmpty($rutas);
        foreach ($rutas as $uri) {
            $this->assertDoesNotMatchRegularExpression('/expediente|legajo|documento|usuario|personal|foto|archivo|familiar|vinculo/', $uri, $uri);
        }
    }

    public function test_the_first_administrator_is_created_with_an_interactive_command(): void
    {
        $this->artisan('plataforma:crear-administrador', ['--nombre' => 'Primera persona', '--email' => 'Primera@Example.test'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres, con letras y números)', 'corta')
            ->expectsQuestion('Repite la contraseña', 'corta')->assertFailed();
        $this->artisan('plataforma:crear-administrador', ['--nombre' => 'Primera persona', '--email' => 'Primera@Example.test'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres, con letras y números)', 'Clave-larga-2026')
            ->expectsQuestion('Repite la contraseña', 'Clave-larga-2026')->assertSuccessful();
        $this->postJson('/api/plataforma/auth/login', ['email' => 'primera@example.test', 'password' => 'Clave-larga-2026'])->assertOk();
        $this->assertSame('administrador.creado', AuditoriaPlataforma::query()->where('accion', 'administrador.creado')->value('accion'));
    }

    /**
     * Caso correcto contra PostgreSQL real (el servidor de Docker): una base temporal con las migraciones institucionales
     * y una carpeta temporal escribible. Ambas llevan nombre único y se borran por nombre al terminar.
     */
    public function test_provisioning_is_marked_when_its_database_is_migrated_and_its_storage_writable(): void
    {
        $sufijo = bin2hex(random_bytes(4));
        $base = 'escalafon_prueba_aprov_'.$sufijo;
        $raiz = storage_path('app/private/instituciones');
        $creoRaiz = ! is_dir($raiz);
        $carpeta = $raiz.'/prueba-aprov-'.$sufijo;
        config(['database.connections.aprovisionamiento_servidor' => [...config('database.connections.pgsql'), 'database' => 'postgres'],
            'database.connections.aprovisionamiento_base' => [...config('database.connections.pgsql'), 'database' => $base]]);
        try {
            DB::connection('aprovisionamiento_servidor')->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('Necesita el servidor PostgreSQL de Docker: '.$e->getMessage());
        }
        $institucion = $this->institucion(['slug' => 'prueba-aprov-'.$sufijo, 'base_datos' => $base, 'almacenamiento' => 'instituciones/prueba-aprov-'.$sufijo]);
        try {
            DB::connection('aprovisionamiento_servidor')->statement('CREATE DATABASE "'.$base.'"');
            $this->artisan('migrate', ['--database' => 'aprovisionamiento_base', '--force' => true])->assertSuccessful();
            mkdir($carpeta, 0755, true);

            $this->artisan('plataforma:verificar-aprovisionamiento', ['slug' => $institucion->slug])
                ->expectsOutputToContain('Institución aprovisionada')->assertSuccessful();
            $institucion->refresh();
            $this->assertTrue($institucion->aprovisionada());
            $this->assertSame(Institucion::REGISTRADA, $institucion->estado, 'Aprovisionar no la activa: se activa desde el panel.');
            $this->assertSame(1, AuditoriaPlataforma::query()->where('institucion_id', $institucion->id)->where('accion', 'institucion.aprovisionada')->count());
            // Ya se puede activar, y su ubicación deja de ser editable.
            $this->entrar();
            $this->postJson("/api/plataforma/instituciones/{$institucion->id}/activar")->assertOk()->assertJsonPath('data.estado', Institucion::ACTIVA);
            $this->patchJson("/api/plataforma/instituciones/{$institucion->id}", ['slug' => 'otro-slug'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        } finally {
            DB::purge('aprovisionamiento_base');
            DB::purge('verificacion_institucion');
            DB::connection('aprovisionamiento_servidor')->statement('DROP DATABASE IF EXISTS "'.$base.'" WITH (FORCE)');
            DB::purge('aprovisionamiento_servidor');
            File::deleteDirectory($carpeta);
            if ($creoRaiz) {
                File::deleteDirectory($raiz);
            }
        }
    }

    public function test_provisioning_is_not_marked_when_its_database_or_storage_are_missing(): void
    {
        $institucion = $this->institucion(['slug' => 'sin-preparar']);
        $this->artisan('plataforma:verificar-aprovisionamiento', ['slug' => 'sin-preparar'])->assertFailed();
        $this->assertNull($institucion->refresh()->aprovisionada_en);
        $this->assertSame('registrada', $institucion->estado);
        $this->artisan('plataforma:verificar-aprovisionamiento', ['slug' => 'no-existe'])->assertFailed();
    }
}
