<?php

namespace Tests\Feature;

use App\Models\Plataforma\AdministradorPlataforma;
use App\Models\Plataforma\AuditoriaPlataforma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

class PasswordPlataformaTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private array $cookies = [];

    private function pedir(string $navegador, string $metodo, string $url, array $datos = []): TestResponse
    {
        config(['auth.defaults.guard' => 'web']);
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->defaultCookies = [];
        $response = $this->withCredentials()->withCookies($this->cookies[$navegador] ?? [])->json($metodo, '/api/plataforma/'.$url, $datos);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$navegador][$cookie->getName()]);
            } else {
                $this->cookies[$navegador][$cookie->getName()] = $response->getCookie($cookie->getName())->getValue();
            }
        }

        return $response;
    }

    private function datos(string $actual = 'Clave-segura-2026'): array
    {
        return ['password_actual' => $actual, 'password' => 'Clave-nueva-2026', 'password_confirmation' => 'Clave-nueva-2026'];
    }

    public function test_change_preserves_current_session_invalidates_others_and_keeps_secrets_out_of_audit(): void
    {
        config(['session.driver' => 'file', 'session.files' => storage_path('framework/sessions')]);
        $admin = $this->administrador();
        $otra = $this->administrador(['email' => 'otra@example.test']);
        foreach (['actual', 'anterior'] as $navegador) {
            $this->pedir($navegador, 'POST', 'auth/login', ['email' => $admin->email, 'password' => 'Clave-segura-2026'])->assertOk();
        }
        $this->pedir('otra', 'POST', 'auth/login', ['email' => $otra->email, 'password' => 'Clave-segura-2026'])->assertOk();
        $this->pedir('actual', 'PUT', 'auth/password', $this->datos())->assertOk()->assertJsonMissingPath('data.administrador.password');
        $this->assertTrue(Hash::check('Clave-nueva-2026', $admin->refresh()->password));
        $this->pedir('actual', 'GET', 'auth/me')->assertOk();
        $this->pedir('anterior', 'GET', 'auth/me')->assertUnauthorized();
        $this->pedir('otra', 'GET', 'auth/me')->assertOk();
        $this->pedir('nuevo', 'POST', 'auth/login', ['email' => $admin->email, 'password' => 'Clave-segura-2026'])->assertUnprocessable();
        $this->pedir('nuevo', 'POST', 'auth/login', ['email' => $admin->email, 'password' => 'Clave-nueva-2026'])->assertOk();
        $evento = AuditoriaPlataforma::query()->where('accion', 'cuenta.password_cambiada')->sole();
        $this->assertSame($admin->id, $evento->administrador_id);
        $this->assertNull($evento->datos_antes);
        $this->assertNull($evento->datos_despues);
    }

    public function test_requires_current_password_confirmation_and_a_different_valid_password(): void
    {
        $this->administrador();
        $this->putJson('/api/plataforma/auth/password', $this->datos())->assertUnauthorized();
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
        $this->putJson('/api/plataforma/auth/password', $this->datos('Incorrecta-2026'))->assertUnprocessable()->assertJsonValidationErrors('password_actual');
        $this->putJson('/api/plataforma/auth/password', [...$this->datos(), 'password_confirmation' => 'Distinta-2026'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/plataforma/auth/password', ['password_actual' => 'Clave-segura-2026', 'password' => 'Clave-segura-2026', 'password_confirmation' => 'Clave-segura-2026'])->assertUnprocessable();
        $this->putJson('/api/plataforma/auth/password', ['password_actual' => 'Clave-segura-2026', 'password' => 'corta', 'password_confirmation' => 'corta'])->assertUnprocessable();
        $this->assertDatabaseCount('administradores_plataforma', 1, 'plataforma');
        $this->assertSame(0, AuditoriaPlataforma::query()->where('accion', 'cuenta.password_cambiada')->count());
    }

    public function test_change_is_rate_limited(): void
    {
        $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
        foreach (range(1, 10) as $intento) {
            $this->putJson('/api/plataforma/auth/password', $this->datos('Incorrecta-2026'))->assertUnprocessable();
        }
        $this->putJson('/api/plataforma/auth/password', $this->datos())->assertStatus(429);
    }

    public function test_deactivated_accounts_cannot_keep_using_the_platform(): void
    {
        $admin = $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => $admin->email, 'password' => 'Clave-segura-2026'])->assertOk();
        AdministradorPlataforma::query()->findOrFail($admin->id)->update(['activo' => false]);
        Auth::guard('plataforma')->forgetUser();
        $this->getJson('/api/plataforma/instituciones')->assertUnauthorized();
    }
}
