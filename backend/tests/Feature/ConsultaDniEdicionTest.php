<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\Ubigeo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

class ConsultaDniEdicionTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.factiliza.token' => 'token-solo-pruebas']);
        Http::preventStrayRequests();
    }

    private function url(Usuario $persona): string
    {
        return '/api/v1/expedientes/'.$persona->id.'/consulta-dni';
    }

    private function proveedor(string $dni = '01234567'): void
    {
        Http::fake(['api.factiliza.com/*' => Http::response(['success' => true, 'data' => [
            'numero' => $dni, 'nombres' => 'ANA MARÍA', 'apellido_paterno' => 'QUISPE', 'apellido_materno' => 'FLORES',
            'departamento' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA',
        ]])]);
    }

    public function test_own_dni_can_be_consulted_without_changing_the_person_until_the_edit_is_saved(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['numero_doc_identidad' => '01234567', 'nombres' => 'Nombre anterior', 'telefono' => '987654321']);
        $ubicacion = Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana']);
        $antes = $persona->refresh()->getAttributes();
        $ficha = '/api/v1/expedientes/'.$persona->id;
        $version = $this->getJson($ficha)->assertOk()->json('data.version');
        $this->proveedor();

        $consulta = $this->postJson($this->url($persona), ['dni' => '01234567'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.nombres', 'ANA MARÍA')->assertJsonPath('data.ubicacion.id', $ubicacion->id);
        $this->assertSame($antes, $persona->refresh()->getAttributes());
        $this->getJson($ficha)->assertOk()->assertJsonPath('data.version', $version);
        $datos = $consulta->json('data');
        $this->withHeader('If-Match', $version)->patchJson($ficha, [
            'nombres' => $datos['nombres'], 'apellido_paterno' => $datos['apellido_paterno'], 'apellido_materno' => $datos['apellido_materno'],
            'ubicacion' => $ubicacion->only(['region', 'provincia', 'distrito']),
        ])->assertOk()->assertJsonPath('data.usuario.values.nombres', 'ANA MARÍA');
        $this->assertSame('987654321', $persona->refresh()->telefono);
        $this->assertSame($ubicacion->id, $persona->ubigeo_id);
    }

    public function test_a_dni_correction_can_be_looked_up_without_creating_another_account(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['numero_doc_identidad' => '87654321']);
        $cuentas = Usuario::query()->count();
        $this->proveedor();
        $this->postJson($this->url($persona), ['dni' => '01234567'])->assertOk()->assertJsonPath('data.dni', '01234567');
        $this->assertSame('87654321', $persona->refresh()->numero_doc_identidad);
        $this->assertSame($cuentas, Usuario::query()->count());
        $this->postJson($this->url($persona), ['dni' => '01234567'])->assertOk()->assertJsonPath('data.origen', 'base_datos');
        Http::assertSentCount(1);
    }

    public function test_a_dni_owned_by_another_person_is_rejected_without_identifying_them_or_calling_the_provider(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['numero_doc_identidad' => '87654321']);
        $otra = Usuario::factory()->create(['numero_doc_identidad' => '01234567']);
        Http::fake();
        $this->postJson($this->url($persona), ['dni' => '01234567'])->assertUnprocessable()
            ->assertJsonValidationErrors('dni')->assertDontSee($otra->id)->assertJsonMissingPath('data');
        Http::assertNothingSent();
        $this->assertSame('87654321', $persona->refresh()->numero_doc_identidad);
    }

    public function test_edit_lookup_requires_editing_and_sensitive_data_permissions(): void
    {
        $persona = Usuario::factory()->create(['numero_doc_identidad' => '01234567']);
        $this->proveedor();
        $this->postJson($this->url($persona), ['dni' => '01234567'])->assertUnauthorized();
        foreach (['sin_edicion' => ['datos_sensibles.ver'], 'sin_sensibles' => ['legajo.editar']] as $rol => $permisos) {
            config(["permisos.roles.$rol" => $permisos]);
            $actor = $this->asignarRol(Usuario::factory()->create(), $rol, [], ['ve_datos_sensibles' => in_array('datos_sensibles.ver', $permisos, true)])->refresh();
            Sanctum::actingAs($actor);
            $this->postJson($this->url($persona), ['dni' => '01234567'])->assertForbidden();
        }
        Http::assertNothingSent();
        config(['permisos.roles.editor' => ['legajo.editar', 'datos_sensibles.ver']]);
        $editor = $this->asignarRol(Usuario::factory()->create(), 'editor', [], ['ve_datos_sensibles' => true])->refresh();
        Sanctum::actingAs($editor);
        $this->postJson($this->url($persona), ['dni' => '01234567'])->assertOk();
    }

    public function test_a_closed_file_cannot_use_the_edit_lookup(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['numero_doc_identidad' => '01234567']);
        Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'cerrado']);
        Http::fake();
        $this->postJson($this->url($persona), ['dni' => '01234567'])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_invalid_dni_and_temporary_passwords_do_not_consume_queries(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['numero_doc_identidad' => '01234567']);
        Http::fake();
        foreach (['123', '123456789', '1234567A', 12345678] as $dni) {
            $this->postJson($this->url($persona), ['dni' => $dni])->assertUnprocessable()->assertJsonValidationErrors('dni');
        }
        $this->actuarComo(Usuario::factory()->create(['debe_cambiar_password' => true]));
        $this->postJson($this->url($persona), ['dni' => '01234567'])->assertForbidden()->assertJsonPath('codigo', 'cambio_password_requerido');
        Http::assertNothingSent();
    }

    public function test_provider_failure_leaves_the_stored_person_untouched(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['numero_doc_identidad' => '01234567']);
        $antes = $persona->refresh()->getAttributes();
        Http::fake(['api.factiliza.com/*' => Http::response([], 503)]);
        $this->postJson($this->url($persona), ['dni' => '01234567'])->assertStatus(503);
        $this->assertSame($antes, $persona->refresh()->getAttributes());
    }

    private function guardarConsulta(): void
    {
        DB::connection('plataforma')->table('identidades_dni')->insert([
            'dni' => '01234567', 'nombres' => 'CONSULTA ANTERIOR', 'apellido_paterno' => 'GUARDADO',
            'apellido_materno' => 'ANTERIOR', 'ubicacion' => null, 'ubicacion_consultada_en' => now()->subDay(),
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
    }

    public function test_explicit_refresh_bypasses_the_directory_but_does_not_change_the_person_or_version(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['numero_doc_identidad' => '01234567']);
        $this->guardarConsulta();
        $antes = $persona->refresh()->getAttributes();
        $ficha = '/api/v1/expedientes/'.$persona->id;
        $version = $this->getJson($ficha)->assertOk()->json('data.version');
        $this->proveedor();

        $this->postJson($this->url($persona), ['dni' => '01234567', 'actualizar' => false])->assertOk()
            ->assertJsonPath('data.origen', 'base_datos')->assertJsonPath('data.nombres', 'CONSULTA ANTERIOR');
        Http::assertNothingSent();
        $this->postJson($this->url($persona), ['dni' => '01234567', 'actualizar' => true])->assertOk()
            ->assertJsonPath('data.origen', 'factiliza')->assertJsonPath('data.nombres', 'ANA MARÍA');
        Http::assertSentCount(1);
        $this->postJson($this->url($persona), ['dni' => '01234567'])->assertOk()
            ->assertJsonPath('data.origen', 'base_datos')->assertJsonPath('data.nombres', 'ANA MARÍA');
        Http::assertSentCount(1);
        $this->assertSame($antes, $persona->refresh()->getAttributes());
        $this->getJson($ficha)->assertOk()->assertJsonPath('data.version', $version);
        $this->postJson($this->url($persona), ['dni' => '01234567', 'actualizar' => true])->assertOk()->assertJsonPath('data.origen', 'factiliza');
        Http::assertSentCount(2);
    }

    public function test_failed_refresh_reports_the_failure_and_preserves_the_directory_and_person(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['numero_doc_identidad' => '01234567']);
        $this->guardarConsulta();
        $antes = $persona->refresh()->getAttributes();
        $consulta = (array) DB::connection('plataforma')->table('identidades_dni')->first();
        Http::fake(['api.factiliza.com/*' => Http::sequence()
            ->push([], 503)->push([], 429)->push([], 404)
            ->push(['success' => true, 'data' => ['numero' => '87654321', 'nombres' => 'OTRA', 'apellido_paterno' => 'PERSONA']])
            ->push(['success' => true, 'data' => ['numero' => '01234567', 'nombres' => '', 'apellido_paterno' => 'INCOMPLETO']])]);

        foreach ([503, 503, 422, 503, 503] as $status) {
            $this->postJson($this->url($persona), ['dni' => '01234567', 'actualizar' => true])->assertStatus($status)->assertJsonMissingPath('data');
            $this->assertSame($consulta, (array) DB::connection('plataforma')->table('identidades_dni')->first());
            $this->assertSame($antes, $persona->refresh()->getAttributes());
        }
        $this->postJson($this->url($persona), ['dni' => '01234567'])->assertOk()
            ->assertJsonPath('data.origen', 'base_datos')->assertJsonPath('data.nombres', 'CONSULTA ANTERIOR');
        Http::assertSentCount(5);
    }

    public function test_refresh_validates_the_option_and_rejects_duplicate_dnis_before_calling_the_provider(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['numero_doc_identidad' => '01234567']);
        Usuario::factory()->create(['numero_doc_identidad' => '87654321']);
        Http::fake();
        $this->postJson($this->url($persona), ['dni' => '01234567', 'actualizar' => 'siempre'])->assertUnprocessable()->assertJsonValidationErrors('actualizar');
        $this->postJson($this->url($persona), ['dni' => '87654321', 'actualizar' => true])->assertUnprocessable()->assertJsonValidationErrors('dni');
        Http::assertNothingSent();
    }
}
