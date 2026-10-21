<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

class ConsultaDniTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const URL = '/api/plataforma/identidad/dni';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.factiliza.token' => 'token-solo-pruebas']);
        Http::preventStrayRequests();
    }

    private function entrar(array $datos = []): void
    {
        $this->administrador($datos);
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
    }

    private function persona(array $datos = []): array
    {
        return ['success' => true, 'data' => [...[
            'numero' => '01234567', 'nombres' => ' Ana María ', 'apellido_paterno' => ' Quispe ', 'apellido_materno' => ' Flores ',
            'direccion' => 'Dato que no se debe guardar', 'ubigeo_reniec' => '010101',
        ], ...$datos]];
    }

    public function test_lookup_keeps_only_names_and_reuses_the_database_without_another_api_call(): void
    {
        $this->entrar();
        Http::fake(['api.factiliza.com/*' => Http::response($this->persona())]);

        $respuesta = $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['data' => [
                'dni' => '01234567', 'nombres' => 'Ana María', 'apellido_paterno' => 'Quispe', 'apellido_materno' => 'Flores', 'origen' => 'factiliza',
            ]]);
        $this->assertStringNotContainsString('token-solo-pruebas', $respuesta->getContent());
        Http::assertSent(fn ($r) => $r->url() === 'https://api.factiliza.com/v1/dni/info/01234567' && $r->hasHeader('Authorization', 'Bearer token-solo-pruebas'));
        $guardada = DB::connection('plataforma')->table('identidades_dni')->sole();
        $this->assertSame('01234567', $guardada->dni);
        $this->assertFalse(property_exists($guardada, 'direccion'));
        $this->assertFalse(property_exists($guardada, 'ubigeo_reniec'));

        config(['services.factiliza.token' => null]);
        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertJsonPath('data.origen', 'base_datos');
        Http::assertSentCount(1);
    }

    public function test_only_platform_accounts_with_definitive_passwords_can_lookup_dni(): void
    {
        Http::fake();
        $this->postJson(self::URL, ['dni' => '01234567'])->assertUnauthorized();
        Sanctum::actingAs(Usuario::factory()->create());
        $this->postJson(self::URL, ['dni' => '01234567'])->assertUnauthorized();
        $this->entrar(['debe_cambiar_password' => true]);
        $this->postJson(self::URL, ['dni' => '01234567'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_invalid_dni_never_consumes_the_external_service(): void
    {
        $this->entrar();
        Http::fake();
        foreach (['123', '123456789', '1234567A', '12 34567', 12345678, null] as $dni) {
            $this->postJson(self::URL, ['dni' => $dni])->assertUnprocessable()->assertJsonValidationErrors('dni');
        }
        Http::assertNothingSent();
    }

    public function test_missing_token_has_a_clear_message_and_does_not_make_requests(): void
    {
        $this->entrar();
        config(['services.factiliza.token' => '']);
        Http::fake();
        $this->postJson(self::URL, ['dni' => '01234567'])->assertStatus(503)->assertJsonPath('message', fn ($m) => str_contains($m, 'manualmente'));
        Http::assertNothingSent();
    }

    public function test_provider_errors_do_not_expose_the_token_or_cache_failed_results(): void
    {
        $this->entrar();
        $secuencia = Http::sequence();
        foreach ([401, 403, 429, 500] as $estado) {
            $secuencia->push(['message' => 'token-solo-pruebas'], $estado);
        }
        Http::fake(['api.factiliza.com/*' => $secuencia]);
        foreach ([401, 403, 429, 500] as $estado) {
            $this->postJson(self::URL, ['dni' => '01234567'])->assertStatus(503)->assertDontSee('token-solo-pruebas');
        }
        $this->assertSame(0, DB::connection('plataforma')->table('identidades_dni')->count());
    }

    public function test_missing_incomplete_and_mismatched_identities_are_not_saved(): void
    {
        $this->entrar();
        $secuencia = Http::sequence()->push([], 404);
        $casos = [['numero' => '87654321'], ['nombres' => '  '], ['apellido_paterno' => null], ['nombres' => ['incorrecto']]];
        foreach ($casos as $datos) {
            $secuencia->push($this->persona($datos));
        }
        Http::fake(['api.factiliza.com/*' => $secuencia]);
        $this->postJson(self::URL, ['dni' => '01234567'])->assertUnprocessable()->assertJsonValidationErrors('dni');
        foreach ($casos as $datos) {
            $this->postJson(self::URL, ['dni' => '01234567'])->assertStatus(503);
        }
        $this->assertSame(0, DB::connection('plataforma')->table('identidades_dni')->count());
    }

    public function test_connection_failure_allows_a_manual_fallback_without_caching(): void
    {
        $this->entrar();
        Http::fake(['api.factiliza.com/*' => Http::failedConnection()]);
        $this->postJson(self::URL, ['dni' => '01234567'])->assertStatus(503)->assertJsonPath('message', fn ($m) => str_contains($m, 'manualmente'));
        $this->assertSame(0, DB::connection('plataforma')->table('identidades_dni')->count());
    }

    public function test_superadministrator_dni_is_saved_and_cannot_be_assigned_twice(): void
    {
        $this->entrar();
        $datos = ['dni' => '01234567', 'nombre' => 'Ana María Quispe Flores', 'email' => 'ana@example.test'];
        $this->postJson('/api/plataforma/superadministradores', $datos)->assertCreated()->assertJsonPath('data.dni', '01234567');
        $this->assertDatabaseHas('administradores_plataforma', ['dni' => '01234567', 'nombre' => $datos['nombre']], 'plataforma');
        $this->postJson('/api/plataforma/superadministradores', [...$datos, 'email' => 'otra@example.test'])->assertUnprocessable()->assertJsonValidationErrors('dni');
        $this->postJson('/api/plataforma/superadministradores', [...$datos, 'dni' => '123', 'email' => 'otra@example.test'])->assertUnprocessable()->assertJsonValidationErrors('dni');
    }
}
