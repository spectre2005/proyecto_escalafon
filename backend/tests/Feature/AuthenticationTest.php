<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\AdministradorPruebaSeeder as AdministradorSeeder;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'admin@example.test';

    private const CLAVE = 'clave-de-prueba-1';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'pruebas.administrador' => ['email' => self::EMAIL, 'password' => self::CLAVE],
            'sanctum.stateful' => ['localhost:5173'],
            // El contenedor fija CACHE_STORE=file y phpunit.xml no lo impone: sin esto, los intentos de
            // login (por correo e IP y el throttle de la ruta) se acumulan entre pruebas y entre ejecuciones.
            // Cada prueba empieza con un limitador vacío en memoria; el limitador sigue activo.
            'cache.limiter' => 'array',
        ]);
        // Como el SPA: las peticiones llegan desde un dominio "stateful" y usan la cookie de sesión.
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function login(string $email = self::EMAIL, string $password = self::CLAVE)
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function assertRechazoGenerico($response): void
    {
        $response->assertUnprocessable()->assertJsonPath('errors.email.0', 'Correo o contraseña incorrectos.');
    }

    public function test_legacy_seeders_do_not_create_access_accounts(): void
    {
        $this->seed(\Database\Seeders\AdministradorSeeder::class);
        $this->assertDatabaseCount('usuarios', 0);
    }

    public function test_reseeding_does_not_change_existing_database_credentials(): void
    {
        $this->seed(AdministradorSeeder::class);
        $admin = Usuario::where('email_institucional', self::EMAIL)->firstOrFail();
        $hash = $admin->password;
        config(['auth.administrador_inicial' => ['email' => 'otro@example.test', 'password' => 'Otra-clave-2026']]);
        $this->seed(\Database\Seeders\AdministradorSeeder::class);
        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertSame(self::EMAIL, $admin->fresh()->email_institucional);
        $this->login()->assertOk();
    }

    public function test_successful_login_returns_user_permissions_and_sensitive_flag(): void
    {
        $this->seed(AdministradorSeeder::class);
        $admin = Usuario::firstOrFail();
        $this->assertNull($admin->ultimo_acceso);

        $response = $this->login()->assertOk()
            ->assertJsonPath('data.usuario.id', $admin->id)
            ->assertJsonPath('data.ve_datos_sensibles', true)
            ->assertJsonMissingPath('data.usuario.password');
        $permisos = $response->json('data.permisos');
        foreach (['personal.ver', 'usuarios.gestionar', 'legajo.ver', 'legajo.editar', 'documento.subir', 'documento.recepcionar', 'seccion07.ver', 'sistema.administrar', 'datos_sensibles.ver'] as $permiso) {
            $this->assertContains($permiso, $permisos);
        }
        // Pendiente de decisión del cliente: nadie lo recibe, ni ADMIN.
        $this->assertNotContains('seccion07.ver_calificacion', $permisos);
        $this->assertNotNull($admin->fresh()->ultimo_acceso);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.usuario.id', $admin->id)->assertJsonPath('data.permisos', $permisos);
    }

    public function test_session_attributes_records_to_the_user_and_logout_closes_it(): void
    {
        $this->seed(AdministradorSeeder::class);
        $admin = Usuario::firstOrFail();
        $person = Usuario::factory()->create();
        $this->getJson('/api/v1/expedientes')->assertUnauthorized();
        $this->login()->assertOk();
        $this->getJson('/api/v1/expedientes')->assertOk();

        $payload = ['apellidos_nombres' => 'Familiar ficticio', 'parentesco' => 'hijo', 'es_derechohabiente' => false];
        $url = '/api/v1/expedientes/'.$person->id.'/familiares';
        $this->postJson($url, $payload + ['registrado_por' => $person->id])->assertUnprocessable();
        $response = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.registrado_por', $admin->id);
        $this->patchJson($url.'/'.$response->json('data.id'), ['registrado_por' => $person->id])->assertUnprocessable();
        $this->postJson('/api/v1/familiares', $payload + ['usuario_id' => $person->id])->assertCreated()->assertJsonPath('data.registrado_por', $admin->id);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        // En HTTP real cada petición resuelve el guard de nuevo; en el test hay que olvidarlo.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/usuarios')->assertUnauthorized();
    }

    public function test_wrong_password_is_rejected_with_generic_message(): void
    {
        $this->seed(AdministradorSeeder::class);
        $this->assertRechazoGenerico($this->login(password: 'incorrecta'));
        $this->assertRechazoGenerico($this->login(email: 'nadie@example.test'));
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_inactive_account_is_rejected_with_the_same_message(): void
    {
        $this->seed(AdministradorSeeder::class);
        Usuario::firstOrFail()->update(['activo' => false]);
        $this->assertRechazoGenerico($this->login());
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_account_without_role_is_rejected_without_confirming_the_password(): void
    {
        Usuario::factory()->create(['email_institucional' => 'sinrol@example.test', 'password' => self::CLAVE]);
        $this->assertRechazoGenerico($this->login('sinrol@example.test'));
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_expired_or_inactive_role_gives_no_permissions(): void
    {
        $this->seed(AdministradorSeeder::class);
        UsuarioRol::query()->update(['vigente_hasta' => today()->subDay()->toDateString()]);
        $this->assertSame([], Usuario::firstOrFail()->permisos());
        $this->assertRechazoGenerico($this->login());

        UsuarioRol::query()->update(['vigente_hasta' => null, 'vigente_desde' => today()->addDay()->toDateString()]);
        $this->assertSame([], Usuario::firstOrFail()->permisos());

        UsuarioRol::query()->update(['vigente_desde' => '2020-01-01']);
        Rol::query()->update(['activo' => false]);
        $this->assertSame([], Usuario::firstOrFail()->permisos());

        // Una sesión abierta pierde el acceso en cuanto el rol deja de estar vigente.
        Rol::query()->update(['activo' => true]);
        $usuario = $this->actuarComo(Usuario::factory()->create());
        $this->getJson('/api/v1/expedientes')->assertOk();
        UsuarioRol::query()->where('usuario_id', $usuario->id)->update(['vigente_hasta' => today()->subDay()->toDateString()]);
        // Siguiente petición: el usuario se vuelve a cargar, como en HTTP real.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($usuario->fresh());
        $this->getJson('/api/v1/expedientes')->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_missing_permission_returns_403(): void
    {
        $asistente = $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
        $this->assertSame(
            ['personal.ver', 'legajo.ver', 'legajo.editar', 'documento.subir', 'documento.recepcionar', 'seccion07.ver'],
            $asistente->permisos(),
        );
        $this->getJson('/api/v1/expedientes')->assertOk();
        $this->getJson('/api/v1/usuarios')->assertOk();
        $this->postJson('/api/v1/usuarios', ['usuarios' => []])->assertForbidden();
        $this->deleteJson('/api/v1/usuarios/'.$asistente->id)->assertForbidden();
        $this->getJson('/api/v1/evaluaciones-desempeno')->assertForbidden();
        $this->assertDatabaseHas('usuarios', ['id' => $asistente->id]);
    }

    public function test_login_is_limited_per_email_and_ip(): void
    {
        $this->seed(AdministradorSeeder::class);
        for ($i = 0; $i < 5; $i++) {
            $this->assertRechazoGenerico($this->login(password: 'incorrecta'));
        }
        // Bloqueada esa cuenta desde esta IP, aun con la clave correcta.
        $this->login()->assertStatus(429)->assertHeader('Retry-After');
        // Otra cuenta desde la misma IP no queda bloqueada.
        $this->assertRechazoGenerico($this->login(email: 'otra@example.test'));

        $this->travel(61)->seconds();
        $this->login()->assertOk();
    }

    public function test_login_requires_csrf_token_outside_test_environment(): void
    {
        $this->seed(AdministradorSeeder::class);
        $this->app->instance('env', 'local');
        $this->login()->assertStatus(419);
        $this->withSession(['_token' => 'test-csrf-token'])->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->login()->assertOk();
    }
}
