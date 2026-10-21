<?php

namespace Tests\Feature;

use App\Models\Plataforma\AdministradorPlataforma;
use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/**
 * Cuentas de superadministrador desde el panel y cambio de la propia contraseña. Las sesiones se prueban como
 * navegadores reales (cookies reenviadas, sesiones en archivo en el storage temporal de las pruebas), igual que
 * SesionRestablecimientoTest: entre peticiones no queda nada en memoria.
 */
class SuperadministradoresTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const CLAVE = 'Clave-segura-2026';

    private string $guardInicial;

    /** @var array<string, array<string, string>> */
    private array $navegadores = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardInicial = (string) config('auth.defaults.guard');
        config(['session.driver' => 'file']);
    }

    private function cuenta(string $email, array $datos = []): AdministradorPlataforma
    {
        return AdministradorPlataforma::query()->create(['nombre' => 'Cuenta '.$email, 'email' => $email, 'password' => self::CLAVE, 'activo' => true, ...$datos]);
    }

    /** Petición del navegador $quien al panel, solo con sus cookies (como un proceso nuevo). */
    private function pedir(string $quien, string $metodo, string $url, array $datos = []): TestResponse
    {
        config(['auth.defaults.guard' => $this->guardInicial]);
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->defaultCookies = [];
        $respuesta = $this->withCredentials()->withCookies($this->navegadores[$quien] ?? [])
            ->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/plataforma'])
            ->json($metodo, 'http://localhost/api/plataforma'.$url, $datos);
        foreach ($respuesta->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->navegadores[$quien][$cookie->getName()]);
            } else {
                $this->navegadores[$quien][$cookie->getName()] = $respuesta->getCookie($cookie->getName())->getValue();
            }
        }

        return $respuesta;
    }

    private function entrar(string $quien, string $email, string $clave = self::CLAVE): TestResponse
    {
        return $this->pedir($quien, 'POST', '/auth/login', ['email' => $email, 'password' => $clave]);
    }

    private function idDe(string $email): string
    {
        return AdministradorPlataforma::query()->where('email', $email)->value('id');
    }

    public function test_accounts_are_listed_created_reset_deactivated_and_reactivated_without_exposing_passwords(): void
    {
        $this->cuenta('ana@example.test');
        $this->entrar('ana', 'ana@example.test')->assertOk();

        $nueva = $this->pedir('ana', 'POST', '/superadministradores', ['nombre' => ' Beto Plataforma ', 'email' => ' BETO@Example.test '])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.email', 'beto@example.test')
            ->assertJsonPath('data.debe_cambiar_password', true)->assertJsonPath('data.activo', true);
        $temporales = [$nueva->json('data.clave_temporal')];
        $this->assertMatchesRegularExpression('/^(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]{16}$/', $temporales[0]);
        $this->pedir('ana', 'POST', '/superadministradores', ['nombre' => 'Otro', 'email' => 'beto@example.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->pedir('ana', 'POST', '/superadministradores', ['nombre' => 'Otro', 'email' => 'otro@example.test', 'password' => 'elegida'])->assertUnprocessable()->assertJsonValidationErrors('password');

        $beto = $this->idDe('beto@example.test');
        $temporales[] = $this->pedir('ana', 'POST', "/superadministradores/{$beto}/restablecer")->assertOk()->json('data.clave_temporal');
        $this->pedir('ana', 'POST', "/superadministradores/{$beto}/desactivar")->assertOk()->assertJsonPath('data.activo', false);
        $this->pedir('ana', 'POST', "/superadministradores/{$beto}/desactivar")->assertConflict();
        $this->pedir('ana', 'POST', "/superadministradores/{$beto}/restablecer")->assertConflict();
        $temporales[] = $this->pedir('ana', 'POST', "/superadministradores/{$beto}/reactivar")->assertOk()->assertJsonPath('data.activo', true)
            ->assertJsonPath('data.debe_cambiar_password', true)->json('data.clave_temporal');
        $this->pedir('ana', 'POST', "/superadministradores/{$beto}/reactivar")->assertConflict();

        $lista = $this->pedir('ana', 'GET', '/superadministradores')->assertOk();
        $this->assertEqualsCanonicalizing(['ana@example.test', 'beto@example.test'], array_column($lista->json('data'), 'email'));
        $this->assertSame([true, false], array_column(collect($lista->json('data'))->sortBy('email')->values()->all(), 'es_actual'));
        $this->assertSame(['id', 'nombre', 'email', 'activo', 'debe_cambiar_password', 'ultimo_acceso', 'created_at', 'es_actual'], array_keys($lista->json('data.0')));
        // Ni la API ni la auditoría guardan contraseñas, temporales ni hashes.
        $auditoria = AuditoriaPlataforma::query()->get();
        $this->assertSame(['administrador.creado', 'administrador.clave_restablecida', 'administrador.desactivado', 'administrador.reactivado'],
            $auditoria->where('accion', '!=', 'sesion.inicio')->sortBy('created_at')->pluck('accion')->values()->all());
        $todo = $lista->getContent().$auditoria->toJson();
        foreach ([...$temporales, self::CLAVE, '$2y$'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $todo);
        }
    }

    public function test_a_temporary_password_must_be_changed_before_using_the_panel(): void
    {
        $this->cuenta('ana@example.test');
        $this->entrar('ana', 'ana@example.test')->assertOk();
        $temporal = $this->pedir('ana', 'POST', '/superadministradores', ['nombre' => 'Beto', 'email' => 'beto@example.test'])->json('data.clave_temporal');

        $this->entrar('beto', 'beto@example.test', $temporal)->assertOk()->assertJsonPath('data.debe_cambiar_password', true);
        $this->pedir('beto', 'GET', '/auth/me')->assertOk()->assertJsonPath('data.debe_cambiar_password', true);
        foreach (['/dashboard', '/instituciones', '/superadministradores'] as $url) {
            $this->pedir('beto', 'GET', $url)->assertForbidden()->assertJsonPath('codigo', 'cambio_password_requerido');
        }

        $nueva = 'Mi-Clave-Propia-2026';
        foreach ([
            [['password_actual' => 'no-es-la-actual', 'password' => $nueva, 'password_confirmation' => $nueva], 'password_actual'],
            [['password_actual' => $temporal, 'password' => $nueva, 'password_confirmation' => 'Otra-Distinta-2026'], 'password'],
            [['password_actual' => $temporal, 'password' => 'corta1', 'password_confirmation' => 'corta1'], 'password'],
            [['password_actual' => $temporal, 'password' => 'SoloLetrasSinNumeros', 'password_confirmation' => 'SoloLetrasSinNumeros'], 'password'],
            [['password_actual' => $temporal, 'password' => $temporal, 'password_confirmation' => $temporal], 'password'],
        ] as [$datos, $campo]) {
            $error = $this->pedir('beto', 'PUT', '/auth/password', $datos)->assertUnprocessable()->assertJsonValidationErrors($campo);
            $this->assertStringNotContainsString($datos['password'], $error->getContent(), 'El error no repite la contraseña enviada.');
        }
        $this->pedir('beto', 'PUT', '/auth/password', ['password_actual' => $temporal, 'password' => $nueva, 'password_confirmation' => $nueva])
            ->assertOk()->assertJsonPath('data.debe_cambiar_password', false);
        // La sesión con la que cambió sigue válida y ya no está restringida.
        $this->pedir('beto', 'GET', '/dashboard')->assertOk();
        $this->assertTrue(Hash::check($nueva, AdministradorPlataforma::query()->where('email', 'beto@example.test')->value('password')));
        $this->assertSame(1, AuditoriaPlataforma::query()->where('accion', 'administrador.clave_cambiada')->count());
    }

    public function test_reset_and_deactivation_close_that_account_sessions_only(): void
    {
        $this->cuenta('ana@example.test');
        $this->cuenta('beto@example.test');
        $this->cuenta('carla@example.test');
        foreach (['ana', 'beto', 'carla'] as $quien) {
            $this->entrar($quien, "{$quien}@example.test")->assertOk();
            $this->pedir($quien, 'GET', '/dashboard')->assertOk();
        }
        // Otro navegador de Carla: no se usará mientras su cuenta esté desactivada.
        $this->entrar('carla_casa', 'carla@example.test')->assertOk();
        $this->pedir('carla_casa', 'GET', '/dashboard')->assertOk();

        // Restablecer cierra las sesiones de esa cuenta; las de quien lo hizo y las de los demás siguen.
        $temporal = $this->pedir('ana', 'POST', '/superadministradores/'.$this->idDe('beto@example.test').'/restablecer')->assertOk()->json('data.clave_temporal');
        $this->pedir('beto', 'GET', '/dashboard')->assertUnauthorized();
        $this->pedir('beto', 'GET', '/auth/me')->assertUnauthorized();
        $this->pedir('ana', 'GET', '/dashboard')->assertOk();
        $this->pedir('carla', 'GET', '/instituciones')->assertOk();
        $this->entrar('beto', 'beto@example.test')->assertUnprocessable();
        $this->entrar('beto', 'beto@example.test', $temporal)->assertOk();

        // Desactivar: la sesión abierta pierde el acceso a todas las rutas (no solo a /auth/me) y no puede volver a entrar.
        $this->pedir('ana', 'POST', '/superadministradores/'.$this->idDe('carla@example.test').'/desactivar')->assertOk();
        $this->pedir('carla', 'GET', '/instituciones')->assertUnauthorized();
        $this->entrar('carla', 'carla@example.test')->assertUnprocessable();
        // La sesión de casa, abierta antes y sin uso mientras estuvo desactivada, tampoco vuelve a valer al reactivarla.
        $temporalCarla = $this->pedir('ana', 'POST', '/superadministradores/'.$this->idDe('carla@example.test').'/reactivar')->assertOk()->json('data.clave_temporal');
        $this->pedir('carla_casa', 'GET', '/dashboard')->assertUnauthorized();
        $this->entrar('carla', 'carla@example.test')->assertUnprocessable();
        $this->entrar('carla', 'carla@example.test', $temporalCarla)->assertOk()->assertJsonPath('data.debe_cambiar_password', true);
        $this->pedir('ana', 'GET', '/dashboard')->assertOk();
    }

    public function test_changing_the_own_password_keeps_this_session_and_closes_the_others(): void
    {
        $this->cuenta('ana@example.test');
        $this->cuenta('beto@example.test');
        $this->entrar('ana_portatil', 'ana@example.test')->assertOk();
        $this->entrar('ana_oficina', 'ana@example.test')->assertOk();
        $this->entrar('beto', 'beto@example.test')->assertOk();

        $nueva = 'Ana-Clave-Nueva-2026';
        $this->pedir('ana_portatil', 'PUT', '/auth/password', ['password_actual' => self::CLAVE, 'password' => $nueva, 'password_confirmation' => $nueva])->assertOk();
        $this->pedir('ana_portatil', 'GET', '/dashboard')->assertOk();
        $this->pedir('ana_oficina', 'GET', '/dashboard')->assertUnauthorized();
        $this->pedir('beto', 'GET', '/dashboard')->assertOk();
    }

    public function test_my_account_shows_its_data_and_previous_access(): void
    {
        $this->cuenta('ana@example.test');
        $this->cuenta('beto@example.test');
        $this->entrar('ana_ayer', 'ana@example.test')->assertOk();
        $this->entrar('ana', 'ana@example.test')->assertOk();
        $this->entrar('beto', 'beto@example.test')->assertOk();
        // Los dos inicios caen en el mismo segundo: uno pasa a ayer para que el acceso anterior sea ese.
        $anterior = AuditoriaPlataforma::query()->where('administrador_id', $this->idDe('ana@example.test'))->where('accion', 'sesion.inicio')->firstOrFail();
        $anterior->forceFill(['created_at' => now()->subDay()])->save();

        $this->pedir('ana', 'GET', '/auth/cuenta')->assertOk()
            ->assertJsonPath('data.cuenta.email', 'ana@example.test')
            ->assertJsonPath('data.cuenta.clave_cambiada_en', null)
            ->assertJsonPath('data.acceso_anterior.id', $anterior->id)
            ->assertJsonMissingPath('data.cuenta.password');
        // Beto solo ve su propio acceso: no tiene uno anterior.
        $this->pedir('beto', 'GET', '/auth/cuenta')->assertOk()->assertJsonPath('data.acceso_anterior', null);
    }

    public function test_my_name_can_be_changed_and_it_is_audited(): void
    {
        $this->cuenta('ana@example.test');
        $this->entrar('ana', 'ana@example.test')->assertOk();

        $this->pedir('ana', 'PATCH', '/auth/cuenta', ['nombre' => '   '])->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->pedir('ana', 'PATCH', '/auth/cuenta', ['nombre' => '  Ana Torres  '])->assertOk()->assertJsonPath('data.administrador.nombre', 'Ana Torres');
        // Sin cambios no se audita de nuevo; el correo no se cambia por aquí.
        $this->pedir('ana', 'PATCH', '/auth/cuenta', ['nombre' => 'Ana Torres', 'email' => 'otra@example.test'])->assertOk();

        $this->assertSame('ana@example.test', AdministradorPlataforma::query()->whereKey($this->idDe('ana@example.test'))->value('email'));
        $this->assertSame(1, AuditoriaPlataforma::query()->where('accion', 'administrador.nombre_cambiado')->count());
        $this->pedir('ana', 'GET', '/auth/cuenta')->assertJsonPath('data.cuenta.nombre', 'Ana Torres');
    }

    public function test_closing_my_other_sessions_keeps_this_one_and_the_password(): void
    {
        $this->cuenta('ana@example.test');
        $this->cuenta('beto@example.test');
        $this->entrar('ana_portatil', 'ana@example.test')->assertOk();
        $this->entrar('ana_oficina', 'ana@example.test')->assertOk();
        $this->entrar('beto', 'beto@example.test')->assertOk();

        $this->pedir('ana_portatil', 'POST', '/auth/cerrar-otras-sesiones', ['password_actual' => 'otra-cosa-1'])->assertUnprocessable()->assertJsonValidationErrors('password_actual');
        $this->pedir('ana_oficina', 'GET', '/dashboard')->assertOk();

        $this->pedir('ana_portatil', 'POST', '/auth/cerrar-otras-sesiones', ['password_actual' => self::CLAVE])->assertOk();
        $this->pedir('ana_portatil', 'GET', '/dashboard')->assertOk();
        $this->pedir('ana_oficina', 'GET', '/dashboard')->assertUnauthorized();
        $this->pedir('beto', 'GET', '/dashboard')->assertOk();
        // La contraseña es la misma: se vuelve a entrar con ella.
        $this->entrar('ana_oficina', 'ana@example.test')->assertOk();
        $this->assertSame(1, AuditoriaPlataforma::query()->where('accion', 'sesion.otras_cerradas')->count());
    }

    public function test_the_platform_never_runs_out_of_active_superadministrators(): void
    {
        $this->cuenta('ana@example.test');
        $this->cuenta('beto@example.test');
        $this->entrar('ana', 'ana@example.test')->assertOk();
        $this->entrar('beto', 'beto@example.test')->assertOk();

        // Nadie se desactiva ni se restablece a sí mismo.
        $this->pedir('ana', 'POST', '/superadministradores/'.$this->idDe('ana@example.test').'/desactivar')->assertConflict();
        $this->pedir('ana', 'POST', '/superadministradores/'.$this->idDe('ana@example.test').'/restablecer')->assertConflict();
        // Ana desactiva a Beto; Beto, ya desactivado, no puede desactivar a Ana: queda una activa.
        $this->pedir('ana', 'POST', '/superadministradores/'.$this->idDe('beto@example.test').'/desactivar')->assertOk();
        $this->pedir('beto', 'POST', '/superadministradores/'.$this->idDe('ana@example.test').'/desactivar')->assertUnauthorized();
        $this->assertSame(['ana@example.test'], AdministradorPlataforma::query()->where('activo', true)->pluck('email')->all());
        // La última activa tampoco puede desactivarse a sí misma.
        $this->pedir('ana', 'POST', '/superadministradores/'.$this->idDe('ana@example.test').'/desactivar')->assertConflict();
        $this->assertSame(1, AdministradorPlataforma::query()->where('activo', true)->count());
    }

    public function test_anonymous_and_institutional_sessions_cannot_manage_platform_accounts(): void
    {
        $ana = $this->cuenta('ana@example.test');
        $rutas = [['GET', '/superadministradores'], ['POST', '/superadministradores'], ['POST', "/superadministradores/{$ana->id}/restablecer"],
            ['POST', "/superadministradores/{$ana->id}/desactivar"], ['POST', "/superadministradores/{$ana->id}/reactivar"], ['PUT', '/auth/password'],
            ['GET', '/auth/cuenta'], ['PATCH', '/auth/cuenta'], ['POST', '/auth/cerrar-otras-sesiones']];
        foreach ($rutas as [$metodo, $url]) {
            $this->pedir('anonimo', $metodo, $url)->assertUnauthorized();
        }
        // Una cuenta institucional autenticada (guard web/sanctum) tampoco entra al panel.
        Sanctum::actingAs(Usuario::factory()->create());
        foreach ($rutas as [$metodo, $url]) {
            $this->json($metodo, 'http://localhost/api/plataforma'.$url)->assertUnauthorized();
        }
        $this->assertSame(1, AdministradorPlataforma::query()->count());
    }
}
