<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Models\UsuarioRol;
use Database\Seeders\AdministradorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_seeder_is_repeatable_and_preserves_changed_password(): void
    {
        $this->seed(AdministradorSeeder::class);
        $admin = Usuario::where('email_institucional', 'admin@uniq.edu.pe')->firstOrFail();
        $this->assertTrue(Hash::check('123456', $admin->password));
        $this->assertTrue($admin->isAdministrator());
        $admin->update(['password' => 'changed-password']);
        $this->seed(AdministradorSeeder::class);
        $this->assertDatabaseCount('usuarios', 1);
        $this->assertDatabaseCount('roles', 1);
        $this->assertDatabaseCount('usuario_roles', 1);
        $this->assertTrue(Hash::check('changed-password', $admin->fresh()->password));
    }

    public function test_login_session_family_attribution_and_logout(): void
    {
        $this->seed(AdministradorSeeder::class);
        $admin = Usuario::firstOrFail();
        $person = Usuario::factory()->create();
        $this->getJson('/api/v1/expedientes')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@uniq.edu.pe', 'password' => '123456'])
            ->assertOk()->assertJsonPath('data.id', $admin->id)->assertJsonMissingPath('data.password');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $admin->id);
        $this->getJson('/api/v1/expedientes')->assertOk();
        $payload = ['apellidos_nombres' => 'Familiar ficticio', 'parentesco' => 'Hija', 'es_derechohabiente' => false];
        $url = '/api/v1/expedientes/'.$person->id.'/familiares';
        $this->postJson($url, $payload + ['registrado_por' => $person->id])->assertUnprocessable();
        $response = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.registrado_por', $admin->id);
        $this->patchJson($url.'/'.$response->json('data.id'), ['registrado_por' => $person->id])->assertUnprocessable();
        $this->postJson('/api/v1/familiares', $payload + ['usuario_id' => $person->id])->assertCreated()->assertJsonPath('data.registrado_por', $admin->id);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/usuarios')->assertUnauthorized();
    }

    public function test_wrong_password_inactive_accounts_and_missing_roles_are_rejected(): void
    {
        $this->seed(AdministradorSeeder::class);
        $credentials = ['email' => 'admin@uniq.edu.pe', 'password' => 'wrong'];
        $this->postJson('/api/v1/auth/login', $credentials)->assertUnprocessable();
        $admin = Usuario::firstOrFail();
        $admin->update(['activo' => false]);
        $credentials['password'] = '123456';
        $this->postJson('/api/v1/auth/login', $credentials)->assertUnprocessable();
        $admin->update(['activo' => true]);
        UsuarioRol::query()->update(['vigente_hasta' => '2020-01-02']);
        $this->postJson('/api/v1/auth/login', $credentials)->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->actingAs($admin)->getJson('/api/v1/usuarios')->assertForbidden();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_login_requires_csrf_token_outside_test_environment(): void
    {
        $this->seed(AdministradorSeeder::class);
        $this->app->instance('env', 'local');
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@uniq.edu.pe', 'password' => '123456'])->assertStatus(419);
        $this->withSession(['_token' => 'test-csrf-token'])->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/api/v1/auth/login', ['email' => 'admin@uniq.edu.pe', 'password' => '123456'])->assertOk();
    }
}
