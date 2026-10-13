<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/**
 * Producción: la API solo responde fuera de local y pruebas si se habilitó (ApiHabilitada), y produccion:verificar
 * rechaza una configuración insegura (el contenedor no arranca) y acepta una segura. Caddy solo pide certificados HTTPS
 * para el dominio de la plataforma y los subdominios de instituciones registradas.
 */
class ProduccionTest extends TestCase
{
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
}
