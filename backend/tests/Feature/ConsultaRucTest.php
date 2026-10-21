<?php

namespace Tests\Feature;

use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

class ConsultaRucTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const URL = '/api/plataforma/identidad/ruc';

    private const RUC = '20100000009';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.factiliza.token' => 'token-solo-pruebas', 'cache.default' => 'array']);
        Http::preventStrayRequests();
    }

    private function entrar(array $datos = []): void
    {
        $this->administrador($datos);
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
    }

    private function empresa(array $cambios = []): array
    {
        return ['success' => true, 'data' => [
            'numero' => self::RUC, 'nombre_o_razon_social' => ' Universidad de Prueba ',
            'tipo_contribuyente' => ' UNIVERSIDAD PUBLICA ',
            'estado' => 'ACTIVO', 'condicion' => 'HABIDO', 'departamento' => 'CUSCO', 'provincia' => 'LA CONVENCION',
            'distrito' => 'SANTA ANA', 'direccion' => ' Av. Universidad 123 ', 'direccion_completa' => ' Av. Universidad 123, Cusco ', 'ubigeo_sunat' => '080901',
            'representante' => 'Dato que no se debe devolver', 'token' => 'token-solo-pruebas', ...$cambios,
        ]];
    }

    public function test_ruc_lookup_uses_the_server_token_and_reuses_a_recent_response_without_creating_an_institution(): void
    {
        $this->entrar();
        Http::fake(['api.factiliza.com/*' => Http::response($this->empresa())]);
        $primera = $this->postJson(self::URL, ['ruc' => self::RUC])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.ruc', self::RUC)->assertJsonPath('data.razon_social', 'Universidad de Prueba')
            ->assertJsonPath('data.tipo_contribuyente', 'UNIVERSIDAD PUBLICA')->assertJsonPath('data.estado', 'ACTIVO')->assertJsonPath('data.condicion', 'HABIDO')
            ->assertJsonPath('data.departamento', 'CUSCO')->assertJsonPath('data.provincia', 'LA CONVENCION')->assertJsonPath('data.distrito', 'SANTA ANA')
            ->assertJsonPath('data.direccion', 'Av. Universidad 123')->assertJsonPath('data.ubigeo_sunat', '080901')
            ->assertJsonPath('data.direccion_completa', 'Av. Universidad 123, Cusco')->assertJsonPath('data.origen', 'factiliza')
            ->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.representante')->assertDontSee('token-solo-pruebas');
        Http::assertSent(fn ($peticion): bool => $peticion->url() === 'https://api.factiliza.com/v1/ruc/info/'.self::RUC
            && $peticion->hasHeader('Authorization', 'Bearer token-solo-pruebas'));
        config(['services.factiliza.token' => null]);
        $this->postJson(self::URL, ['ruc' => self::RUC])->assertOk()->assertJsonPath('data.origen', 'cache')
            ->assertJsonPath('data.tipo_contribuyente', 'UNIVERSIDAD PUBLICA')->assertJsonPath('data.direccion', 'Av. Universidad 123')->assertJsonPath('data.ubigeo_sunat', '080901')
            ->assertJsonPath('data.consultado_en', $primera->json('data.consultado_en'));
        Http::assertSentCount(1);
        $this->assertSame(0, Institucion::query()->count());
    }

    public function test_only_platform_accounts_with_definitive_passwords_can_query_ruc(): void
    {
        Http::fake();
        $this->postJson(self::URL, ['ruc' => self::RUC])->assertUnauthorized();
        Sanctum::actingAs(Usuario::factory()->create());
        $this->postJson(self::URL, ['ruc' => self::RUC])->assertUnauthorized();
        $this->entrar(['debe_cambiar_password' => true]);
        $this->postJson(self::URL, ['ruc' => self::RUC])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_invalid_ruc_never_consumes_the_provider(): void
    {
        $this->entrar();
        Http::fake();
        foreach (['123', '20100000008', '30100000003', '2010000000A', '201 0000009', 20100000009, null, [self::RUC]] as $ruc) {
            $this->postJson(self::URL, ['ruc' => $ruc])->assertUnprocessable()->assertJsonValidationErrors('ruc');
        }
        $this->postJson(self::URL, ['ruc' => self::RUC, 'actualizar' => 'si'])->assertUnprocessable()->assertJsonValidationErrors('actualizar');
        Http::assertNothingSent();
    }

    public function test_expired_cache_is_refreshed_and_an_explicit_refresh_does_not_reuse_old_tax_data(): void
    {
        $this->entrar();
        Http::fake(['api.factiliza.com/*' => Http::sequence()->push($this->empresa())->push($this->empresa(['estado' => 'BAJA DEFINITIVA']))
            ->push($this->empresa(['estado' => 'ACTIVO']))]);
        $this->postJson(self::URL, ['ruc' => self::RUC])->assertOk();
        $this->postJson(self::URL, ['ruc' => self::RUC, 'actualizar' => true])->assertOk()->assertJsonPath('data.estado', 'BAJA DEFINITIVA')->assertJsonPath('data.origen', 'factiliza');
        $this->postJson(self::URL, ['ruc' => self::RUC])->assertOk()->assertJsonPath('data.estado', 'BAJA DEFINITIVA')->assertJsonPath('data.origen', 'cache');
        $this->travel(25)->hours();
        $this->postJson(self::URL, ['ruc' => self::RUC])->assertOk()->assertJsonPath('data.estado', 'ACTIVO')->assertJsonPath('data.origen', 'factiliza');
        Http::assertSentCount(3);
    }

    public function test_provider_errors_are_not_cached_or_exposed_and_a_failed_refresh_keeps_the_last_good_response(): void
    {
        $this->entrar();
        $secuencia = Http::sequence();
        foreach ([400, 401, 403, 429, 500, 302] as $estado) {
            $secuencia->push(['message' => 'token-solo-pruebas'], $estado);
        }
        $secuencia->push($this->empresa())->push([], 500);
        Http::fake(['api.factiliza.com/*' => $secuencia]);
        foreach ([400, 401, 403, 429, 500, 302] as $estado) {
            $this->postJson(self::URL, ['ruc' => self::RUC])->assertStatus(503)->assertDontSee('token-solo-pruebas');
            $this->assertNull(Cache::get('plataforma:consulta-ruc:v2:'.self::RUC));
        }
        $this->postJson(self::URL, ['ruc' => self::RUC])->assertOk();
        $this->postJson(self::URL, ['ruc' => self::RUC, 'actualizar' => true])->assertStatus(503);
        $this->postJson(self::URL, ['ruc' => self::RUC])->assertOk()->assertJsonPath('data.origen', 'cache');
        Http::assertSentCount(8);
    }

    public function test_not_found_incomplete_or_mismatched_records_cannot_autofill_another_taxpayer(): void
    {
        $this->entrar();
        $casos = [['numero' => '20600000005'], ['nombre_o_razon_social' => '   '], ['nombre_o_razon_social' => ['invalido']], ['estado' => ['invalido']],
            ['tipo_contribuyente' => ['invalido']], ['direccion' => ['invalida']], ['ubigeo_sunat' => '08090X']];
        $secuencia = Http::sequence()->push([], 404)->push(['success' => true, 'data' => null]);
        foreach ($casos as $cambios) {
            $secuencia->push($this->empresa($cambios));
        }
        Http::fake(['api.factiliza.com/*' => $secuencia]);
        $this->postJson(self::URL, ['ruc' => self::RUC])->assertUnprocessable()->assertJsonValidationErrors('ruc');
        for ($indice = 0; $indice <= count($casos); $indice++) {
            $this->postJson(self::URL, ['ruc' => self::RUC])->assertStatus(503);
        }
        $this->assertNull(Cache::get('plataforma:consulta-ruc:v2:'.self::RUC));
    }

    public function test_new_tax_fields_are_loaded_even_if_an_older_cached_response_exists(): void
    {
        $this->entrar();
        Cache::put('plataforma:consulta-ruc:v1:'.self::RUC, ['ruc' => self::RUC, 'razon_social' => 'Nombre anterior'], now()->addDay());
        Http::fake(['api.factiliza.com/*' => Http::response($this->empresa())]);
        $this->postJson(self::URL, ['ruc' => self::RUC])->assertOk()->assertJsonPath('data.tipo_contribuyente', 'UNIVERSIDAD PUBLICA')
            ->assertJsonPath('data.ubigeo_sunat', '080901')->assertJsonPath('data.origen', 'factiliza');
        Http::assertSentCount(1);
    }

    public function test_missing_optional_tax_data_remains_null_instead_of_being_invented(): void
    {
        $this->entrar();
        Http::fake(['api.factiliza.com/*' => Http::response(['success' => true, 'data' => ['numero' => self::RUC, 'nombre_o_razon_social' => 'Universidad de Prueba']])]);
        $respuesta = $this->postJson(self::URL, ['ruc' => self::RUC])->assertOk();
        foreach (['tipo_contribuyente', 'estado', 'condicion', 'departamento', 'provincia', 'distrito', 'direccion', 'direccion_completa', 'ubigeo_sunat'] as $campo) {
            $respuesta->assertJsonPath('data.'.$campo, null);
        }
    }

    public function test_missing_credentials_and_connection_failure_allow_manual_registration(): void
    {
        $this->entrar();
        config(['services.factiliza.token' => null]);
        Http::fake(['api.factiliza.com/*' => Http::failedConnection()]);
        $this->postJson(self::URL, ['ruc' => self::RUC])->assertStatus(503)->assertJsonPath('message', fn ($mensaje): bool => str_contains($mensaje, 'manualmente'));
        Http::assertNothingSent();
        config(['services.factiliza.token' => 'token-solo-pruebas']);
        $this->postJson(self::URL, ['ruc' => self::RUC])->assertStatus(503)->assertJsonPath('message', fn ($mensaje): bool => str_contains($mensaje, 'manualmente'));
        $this->postJson('/api/plataforma/instituciones', ['nombre' => 'Universidad de Prueba', 'slug' => 'universidad-prueba', 'ruc' => self::RUC, 'razon_social' => 'Universidad de Prueba'])->assertCreated();
    }

    public function test_registration_keeps_ruc_and_legal_name_and_search_finds_both_without_contacting_factiliza(): void
    {
        $this->entrar();
        $this->institucion(['nombre' => 'Otra institución', 'slug' => 'otra']);
        $id = $this->postJson('/api/plataforma/instituciones', ['nombre' => 'Nombre abreviado', 'slug' => 'entidad-ruc', 'ruc' => self::RUC, 'razon_social' => ' Universidad de Prueba '])
            ->assertCreated()->assertJsonPath('data.ruc', self::RUC)->assertJsonPath('data.razon_social', 'Universidad de Prueba')
            ->assertJsonPath('data.estado', Institucion::REGISTRADA)->assertJsonPath('data.aprovisionada', false)->json('data.id');
        foreach ([self::RUC, 'universidad de prueba', 'nombre abreviado', 'entidad-ruc'] as $busqueda) {
            $this->getJson('/api/plataforma/instituciones?q='.urlencode($busqueda))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        }
        $evento = AuditoriaPlataforma::query()->where('accion', 'institucion.registrada')->sole();
        $this->assertSame(self::RUC, $evento->datos_despues['ruc']);
        $this->assertSame('Universidad de Prueba', $evento->datos_despues['razon_social']);
        $this->assertSame('escalafon_entidad_ruc', Institucion::query()->findOrFail($id)->base_datos);
        Http::assertNothingSent();
    }

    public function test_registration_rejects_invalid_ruc_or_legal_name_without_saving_a_partial_institution(): void
    {
        $this->entrar();
        foreach ([['ruc' => '20100000008'], ['ruc' => 20100000009], ['razon_social' => 'ab'], ['razon_social' => ['invalida']]] as $datos) {
            $this->postJson('/api/plataforma/instituciones', ['nombre' => 'Prueba', 'slug' => 'prueba', ...$datos])->assertUnprocessable()->assertJsonValidationErrors(array_keys($datos));
        }
        $this->assertSame(0, Institucion::query()->count());
        Http::assertNothingSent();
    }

    public function test_editing_the_ruc_keeps_the_institution_name_location_and_contract_and_rejects_non_text_identity(): void
    {
        $this->entrar();
        $institucion = $this->institucion(['nombre' => 'Nombre abreviado', 'slug' => 'entidad', 'estado' => Institucion::ACTIVA, 'aprovisionada_en' => now(),
            'contrato_numero' => 'C-001', 'contacto_email' => 'orh@example.test']);
        $url = '/api/plataforma/instituciones/'.$institucion->id;
        $datosOriginales = $institucion->only(['nombre', 'slug', 'base_datos', 'almacenamiento', 'contrato_numero', 'contacto_email']);
        $this->patchJson($url, ['ruc' => self::RUC, 'razon_social' => ' Universidad de Prueba '])->assertOk()
            ->assertJsonPath('data.ruc', self::RUC)->assertJsonPath('data.razon_social', 'Universidad de Prueba');
        foreach ([['ruc' => 20100000009], ['ruc' => [self::RUC]], ['razon_social' => ['invalida']]] as $datos) {
            $this->patchJson($url, $datos)->assertUnprocessable()->assertJsonValidationErrors(array_keys($datos));
        }
        $this->assertSame($datosOriginales, $institucion->refresh()->only(array_keys($datosOriginales)));
        $this->assertSame(self::RUC, $institucion->ruc);
        Http::assertNothingSent();
    }
}
