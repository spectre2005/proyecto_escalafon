<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Database\Seeders\AdministradorSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Contraseña temporal (debe_cambiar_password): con ella solo se consulta la sesión, se cierra o se cambia la contraseña;
 * el resto de la API responde 403 con «codigo». Al cambiarla (con la actual) se libera el acceso.
 */
class CambioPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'admin@example.test';

    private const TEMPORAL = 'Temporal-2026';

    private const NUEVA = 'Nueva-clave-2026';

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.administrador_inicial' => ['email' => self::EMAIL, 'password' => self::TEMPORAL],
            'sanctum.stateful' => ['localhost:5173'], 'cache.limiter' => 'array']);
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->seed(AdministradorSeeder::class);
    }

    private function conTemporal(): Usuario
    {
        $usuario = Usuario::query()->where('email_institucional', self::EMAIL)->firstOrFail();
        $usuario->forceFill(['debe_cambiar_password' => true])->save();

        return $usuario;
    }

    private function cambiar(array $datos)
    {
        return $this->putJson('/api/v1/auth/password', $datos);
    }

    public function test_a_temporary_password_only_allows_the_session_and_the_change(): void
    {
        $this->conTemporal();
        $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::TEMPORAL])->assertOk()
            ->assertJsonPath('data.debe_cambiar_password', true);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.debe_cambiar_password', true);
        $this->getJson('/api/v1/institucion')->assertForbidden()->assertJsonPath('codigo', 'cambio_password_requerido');
        $this->getJson('/api/v1/usuarios')->assertForbidden()->assertJsonPath('codigo', 'cambio_password_requerido');

        $this->cambiar(['password_actual' => self::TEMPORAL, 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA])->assertOk()
            ->assertJsonPath('data.debe_cambiar_password', false);
        $this->getJson('/api/v1/institucion')->assertOk();
        $this->assertTrue(Hash::check(self::NUEVA, Usuario::query()->where('email_institucional', self::EMAIL)->value('password')));
    }

    public function test_the_change_requires_the_current_password_and_a_new_valid_one(): void
    {
        $this->conTemporal();
        $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::TEMPORAL])->assertOk();

        $this->cambiar(['password_actual' => 'otra-cosa-1', 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA])
            ->assertUnprocessable()->assertJsonValidationErrors('password_actual');
        $this->cambiar(['password_actual' => self::TEMPORAL, 'password' => self::TEMPORAL, 'password_confirmation' => self::TEMPORAL])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->cambiar(['password_actual' => self::TEMPORAL, 'password' => self::NUEVA, 'password_confirmation' => 'Distinta-2026'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->cambiar(['password_actual' => self::TEMPORAL, 'password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->getJson('/api/v1/institucion')->assertForbidden();
    }

    public function test_the_change_needs_a_session_and_a_normal_account_is_not_blocked(): void
    {
        $this->cambiar(['password_actual' => self::TEMPORAL, 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA])->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::TEMPORAL])->assertOk()
            ->assertJsonPath('data.debe_cambiar_password', false);
        $this->getJson('/api/v1/institucion')->assertOk();
    }

    public function test_a_normal_account_can_change_password_without_being_temporary(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::TEMPORAL])->assertOk();
        $this->cambiar(['password_actual' => self::TEMPORAL, 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA])
            ->assertOk()->assertJsonPath('data.debe_cambiar_password', false)
            ->assertJsonPath('data.entorno', 'Institucional');
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertTrue(Hash::check(self::NUEVA, Usuario::query()->where('email_institucional', self::EMAIL)->value('password')));
    }

    public function test_the_production_password_policy_explains_what_is_missing(): void
    {
        $this->conTemporal();
        $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::TEMPORAL])->assertOk();
        // En producción, Password::defaults() exige 10 caracteres, mayúsculas, minúsculas y números. Fuera de «testing»
        // se activa el CSRF; aquí se prueban los mensajes (el CSRF tiene su propia prueba en AuthenticationTest).
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['app.api_habilitada' => true]);

        $this->cambiar(['password_actual' => self::TEMPORAL, 'password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'])
            ->assertUnprocessable()->assertJsonPath('errors.password', [
                'La nueva contraseña debe tener al menos 10 caracteres.',
                'La nueva contraseña debe tener mayúsculas y minúsculas.',
                'La nueva contraseña debe tener números.',
            ]);
    }
}
