<?php

namespace Tests\Feature;

use App\Models\RegimenNormativo;
use App\Models\Ubigeo;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateExpedienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actuarComo();
    }

    public function test_edit_saves_contact_and_location_and_returns_fresh_general_data(): void
    {
        Ubigeo::factory()->create(['region' => 'REGION FICTICIA', 'provincia' => 'PROVINCIA FICTICIA', 'distrito' => 'DISTRITO FICTICIO']);
        $person = Usuario::factory()->create();
        $this->patchJson('/api/v1/expedientes/'.$person->id, [
            'nombres' => $person->nombres,
            'telefono' => '999111222',
            'email_institucional' => 'PRUEBA@example.test',
            'ubicacion' => ['region' => 'Region ficticia', 'provincia' => 'Provincia ficticia', 'distrito' => 'Distrito ficticio'],
        ])->assertOk()->assertJsonPath('data.usuario.values.nombres', $person->nombres)
            ->assertJsonPath('data.usuario.values.email_institucional', 'prueba@example.test')
            ->assertJsonPath('data.usuario.values.region', 'REGION FICTICIA')
            ->assertJsonPath('data.usuario.values.provincia', 'PROVINCIA FICTICIA')
            ->assertJsonPath('data.usuario.values.distrito', 'DISTRITO FICTICIO')
            ->assertJsonMissingPath('data.usuario.values.ubigeo_id')
            ->assertJsonMissingPath('data.usuario.values.password');
        $this->assertDatabaseHas('usuarios', ['id' => $person->id, 'nombres' => $person->nombres]);
        $this->assertDatabaseHas('ubigeo', ['id' => $person->refresh()->ubigeo_id, 'distrito' => 'DISTRITO FICTICIO']);
    }

    public function test_reuses_location_case_insensitively_and_does_not_change_other_people(): void
    {
        $location = Ubigeo::factory()->create(['region' => 'Region de prueba', 'provincia' => 'Provincia de prueba', 'distrito' => 'Distrito de prueba']);
        Ubigeo::factory()->create(['region' => 'Otra region', 'provincia' => 'Otra provincia', 'distrito' => 'Otro distrito']);
        $other = Usuario::factory()->create(['ubigeo_id' => $location->id]);
        $person = Usuario::factory()->create();
        $uri = '/api/v1/expedientes/'.$person->id;
        $this->patchJson($uri, ['ubicacion' => ['region' => 'REGION DE PRUEBA', 'provincia' => 'PROVINCIA DE PRUEBA', 'distrito' => 'DISTRITO DE PRUEBA']])->assertOk();
        $this->assertSame($location->id, $person->refresh()->ubigeo_id);
        $this->assertDatabaseCount('ubigeo', 2);
        $this->patchJson($uri, ['ubicacion' => ['region' => 'Otra region', 'provincia' => 'Otra provincia', 'distrito' => 'Otro distrito']])->assertUnprocessable()->assertJsonValidationErrors('ubicacion.region');
        $this->assertSame($location->id, $person->refresh()->ubigeo_id);
        $this->patchJson($uri, ['ubicacion' => ['region' => 'REGION DE PRUEBA', 'provincia' => 'PROVINCIA DE PRUEBA', 'distrito' => 'DISTRITO DE PRUEBA']])->assertOk();
        $this->assertSame($location->id, $other->refresh()->ubigeo_id);
        $this->assertSame('Distrito de prueba', $location->refresh()->distrito);
        $this->patchJson($uri, ['ubicacion' => null])->assertUnprocessable()->assertJsonValidationErrors(['ubicacion.region', 'ubicacion.provincia', 'ubicacion.distrito']);
        $this->assertSame($location->id, $person->refresh()->ubigeo_id);
    }

    public function test_location_options_follow_region_and_province(): void
    {
        Ubigeo::factory()->create(['region' => 'A', 'provincia' => 'B', 'distrito' => 'C']);
        Ubigeo::factory()->create(['region' => 'A', 'provincia' => 'D', 'distrito' => 'E']);
        Ubigeo::factory()->create(['region' => 'X', 'provincia' => 'Y', 'distrito' => 'Z']);
        $this->getJson('/api/v1/ubicaciones')->assertOk()->assertExactJson(['data' => ['A', 'X']]);
        $this->getJson('/api/v1/ubicaciones?region=A')->assertOk()->assertExactJson(['data' => ['B', 'D']]);
        $this->getJson('/api/v1/ubicaciones?region=A&provincia=B')->assertOk()->assertExactJson(['data' => ['C']]);
    }

    public function test_nationality_is_not_editable_and_the_law_must_match_staff_type(): void
    {
        $ley = fn (string $codigo): string => RegimenNormativo::query()->where('codigo', $codigo)->value('id');
        $person = Usuario::factory()->create(['tipo_personal' => 'docente', 'regimen_laboral' => '30220', 'regimen_normativo_id' => $ley('30220'), 'nacionalidad' => 'Peruano']);
        $uri = '/api/v1/expedientes/'.$person->id;
        $this->patchJson($uri, ['nacionalidad' => 'Otra'])->assertUnprocessable()->assertJsonValidationErrors('nacionalidad');
        $this->assertSame('Peruano', $person->refresh()->nacionalidad);
        // La ley se edita en regimen_normativo_id; regimen_laboral queda como respaldo y no se edita.
        $this->patchJson($uri, ['regimen_laboral' => 'CAS'])->assertUnprocessable()->assertJsonValidationErrors('regimen_laboral');
        $this->patchJson($uri, ['regimen_normativo_id' => $ley('1057')])->assertUnprocessable()->assertJsonValidationErrors('regimen_normativo_id');
        $this->patchJson($uri, ['tipo_personal' => 'administrativo'])->assertUnprocessable()->assertJsonValidationErrors('regimen_normativo_id');
        foreach (['1057' => 'CAS', '276' => '276', '728' => '728', '30057' => '30057'] as $codigo => $respaldo) {
            $this->patchJson($uri, ['tipo_personal' => 'administrativo', 'regimen_normativo_id' => $ley($codigo)])->assertOk()
                ->assertJsonPath('data.regimen.ley.codigo', (string) $codigo);
            $this->assertSame($respaldo, $person->refresh()->regimen_laboral);
        }
        $this->patchJson($uri, ['tipo_personal' => 'docente', 'regimen_normativo_id' => $ley('30220')])->assertOk();
        $this->assertSame('30220', $person->refresh()->regimen_laboral);
    }

    public function test_edit_requires_eight_digit_dni_and_nine_digit_phone(): void
    {
        $person = Usuario::factory()->create(['tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => '12345678']);
        $uri = '/api/v1/expedientes/'.$person->id;
        foreach (['1234567', '123456789', '1234567a'] as $dni) {
            $this->patchJson($uri, ['numero_doc_identidad' => $dni])->assertUnprocessable()->assertJsonValidationErrors('numero_doc_identidad');
        }
        foreach (['12345678', '1234567890', '91234567a', '+51912345678'] as $phone) {
            $this->patchJson($uri, ['telefono' => $phone])->assertUnprocessable()->assertJsonValidationErrors('telefono');
        }
        $this->patchJson($uri, ['numero_doc_identidad' => '87654321', 'telefono' => '912345678'])->assertUnprocessable()->assertJsonValidationErrors('numero_doc_identidad');
        $this->patchJson($uri, ['telefono' => '912345678'])->assertOk();
        $this->assertDatabaseHas('usuarios', ['id' => $person->id, 'numero_doc_identidad' => '12345678', 'telefono' => '912345678']);
        $this->patchJson($uri, ['telefono' => null])->assertOk();
        $this->assertNull($person->refresh()->telefono);
    }

    public function test_complete_location_catalog_includes_ids_and_parent_names(): void
    {
        $location = Ubigeo::factory()->create(['region' => 'A', 'provincia' => 'B', 'distrito' => 'C']);
        $this->getJson('/api/v1/ubicaciones?completo=1')->assertOk()->assertExactJson([
            'data' => [['id' => $location->id, 'region' => 'A', 'provincia' => 'B', 'distrito' => 'C']],
        ]);
    }

    public function test_invalid_and_privileged_fields_are_rejected_without_partial_writes(): void
    {
        $person = Usuario::factory()->create();
        $uri = '/api/v1/expedientes/'.$person->id;
        foreach ([['password' => 'secret'], ['activo' => 'invalid'], ['tipo_personal' => 'invalid'], ['ubicacion' => []], ['ubicacion' => ['region' => 'X']], ['fecha_nacimiento' => '2999-01-01']] as $invalid) {
            $this->patchJson($uri, ['nombres' => 'No guardar', ...$invalid])->assertUnprocessable();
        }
        $this->assertNotSame('No guardar', $person->refresh()->nombres);
        $this->assertDatabaseCount('ubigeo', 0);
    }

    public function test_location_not_in_catalog_is_rejected_without_creating_a_record(): void
    {
        $person = Usuario::factory()->create();
        $this->patchJson('/api/v1/expedientes/'.$person->id, [
            'ubicacion' => ['region' => 'Inventada', 'provincia' => 'Inventada', 'distrito' => 'Inventado'],
        ])->assertUnprocessable()->assertJsonValidationErrors('ubicacion.distrito');
        $this->assertNull($person->refresh()->ubigeo_id);
        $this->assertDatabaseCount('ubigeo', 0);
    }

    public function test_duplicate_email_is_rejected_before_creating_location(): void
    {
        Usuario::factory()->create(['email_institucional' => 'existente@example.test']);
        Ubigeo::factory()->create(['region' => 'X', 'provincia' => 'Y', 'distrito' => 'Z']);
        $person = Usuario::factory()->create();
        $this->patchJson('/api/v1/expedientes/'.$person->id, [
            'email_institucional' => 'EXISTENTE@example.test',
            'ubicacion' => ['region' => 'X', 'provincia' => 'Y', 'distrito' => 'Z'],
        ])->assertUnprocessable()->assertJsonValidationErrors('email_institucional');
        $this->assertDatabaseCount('ubigeo', 1);
    }

    public function test_labor_fields_and_flags_can_be_edited_and_cleared(): void
    {
        $unit = UnidadOrganica::factory()->create();
        $regime = RegimenNormativo::query()->where('codigo', '1057')->firstOrFail();
        $person = Usuario::factory()->create();
        $uri = '/api/v1/expedientes/'.$person->id;
        $this->patchJson($uri, [
            'nombre_puesto' => 'Puesto de prueba', 'unidad_organica_id' => $unit->id,
            'regimen_normativo_id' => $regime->id,
            'tipo_personal' => 'administrativo', 'con_discapacidad' => true, 'tiene_derechohabientes' => true,
        ])->assertOk()->assertJsonPath('data.usuario.values.unidad_organica_id', $unit->nombre)
            ->assertJsonPath('data.edit_values.unidad_organica_id', $unit->id)
            ->assertJsonPath('data.usuario.values.nombre_puesto', 'Puesto de prueba')
            ->assertJsonPath('data.usuario.values.con_discapacidad', true)
            ->assertJsonPath('data.usuario.values.activo', false);
        $this->assertDatabaseHas('usuarios', ['id' => $person->id, 'unidad_organica_id' => $unit->id, 'regimen_normativo_id' => $regime->id]);
        $this->patchJson($uri, ['unidad_organica_id' => null, 'regimen_normativo_id' => null, 'con_discapacidad' => false])
            ->assertOk()->assertJsonPath('data.edit_values.unidad_organica_id', null)
            ->assertJsonPath('data.usuario.values.con_discapacidad', false)->assertJsonPath('data.usuario.values.activo', false);
        // Las banderas de la persona vuelven a Sin responder (null).
        $this->patchJson($uri, ['con_discapacidad' => null, 'tiene_derechohabientes' => null])->assertOk()
            ->assertJsonPath('data.usuario.values.con_discapacidad', null)->assertJsonPath('data.usuario.values.tiene_derechohabientes', null);
        // Las del puesto se registran solo en el vínculo laboral.
        $this->patchJson($uri, ['obligado_djbr' => true, 'exige_colegiatura' => true, 'exige_estudios_basicos' => true, 'tiene_periodo_prueba' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['obligado_djbr', 'exige_colegiatura', 'exige_estudios_basicos', 'tiene_periodo_prueba'])
            ->assertJsonPath('errors.obligado_djbr.0', 'Las condiciones del puesto se registran en el vínculo laboral (sección 02).');
        $this->patchJson($uri, ['unidad_organica_id' => '00000000-0000-4000-8000-000000000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('unidad_organica_id');
        $this->getJson('/api/v1/expediente-opciones')->assertOk()
            ->assertJsonPath('data.unidades.0.id', $unit->id)->assertJsonPath('data.regimenes.0.id', $regime->id);
    }

    public function test_registered_identity_cannot_be_changed_or_cleared_even_without_a_version_header(): void
    {
        Http::fake();
        $location = Ubigeo::factory()->create();
        $person = Usuario::factory()->create(['numero_doc_identidad' => '12345678', 'apellido_materno' => 'Registrado', 'ubigeo_id' => $location->id, 'telefono' => '987654321']);
        $uri = '/api/v1/expedientes/'.$person->id;
        $response = $this->getJson($uri)->assertOk();
        foreach (['nombres', 'apellido_paterno', 'apellido_materno', 'numero_doc_identidad', 'tipo_doc_identidad', 'nacionalidad', 'ubicacion.region', 'ubicacion.provincia', 'ubicacion.distrito'] as $campo) {
            $this->assertContains($campo, $response->json('data.campos_identidad_bloqueados'));
        }
        foreach (['nombres', 'apellido_paterno', 'apellido_materno', 'nacionalidad'] as $campo) {
            foreach (['Otro dato', null, ''] as $valor) {
                $this->patchJson($uri, [$campo => $valor, 'telefono' => '912345678'])->assertUnprocessable()->assertJsonValidationErrors($campo);
            }
        }
        foreach (['pasaporte', 'carnet_extranjeria', 'libreta_electoral'] as $tipo) {
            $this->patchJson($uri, ['tipo_doc_identidad' => $tipo])->assertUnprocessable()->assertJsonValidationErrors('tipo_doc_identidad');
        }
        $this->patchJson($uri, ['numero_doc_identidad' => '00112233'])->assertUnprocessable()->assertJsonValidationErrors('numero_doc_identidad');
        $this->assertSame('12345678', $person->refresh()->numero_doc_identidad);
        $this->assertSame('987654321', $person->telefono);
        $this->patchJson($uri, $person->only(['nombres', 'apellido_paterno', 'apellido_materno', 'nacionalidad', 'numero_doc_identidad', 'tipo_doc_identidad']))->assertOk();
        Http::assertNothingSent();
    }

    public function test_missing_identity_can_be_completed_once_and_contact_and_personal_details_remain_editable(): void
    {
        Http::fake();
        $location = Ubigeo::factory()->create();
        $person = Usuario::factory()->create(['apellido_materno' => null, 'nacionalidad' => null]);
        $uri = '/api/v1/expedientes/'.$person->id;
        $before = $this->getJson($uri)->assertOk()->json('data.campos_identidad_bloqueados');
        foreach (['apellido_materno', 'nacionalidad', 'fecha_nacimiento', 'ubicacion.region'] as $campo) {
            $this->assertNotContains($campo, $before);
        }
        $response = $this->patchJson($uri, [
            'apellido_materno' => 'Flores', 'nacionalidad' => 'Peruano',
            'ubicacion' => $location->only(['region', 'provincia', 'distrito']),
            'fecha_nacimiento' => '1990-02-03', 'domicilio' => 'Domicilio inicial', 'estado_civil' => 'soltero',
        ])->assertOk()->assertJsonPath('data.usuario.values.apellido_materno', 'Flores');
        foreach (['apellido_materno', 'nacionalidad', 'ubicacion.region', 'ubicacion.provincia', 'ubicacion.distrito'] as $campo) {
            $this->assertContains($campo, $response->json('data.campos_identidad_bloqueados'));
        }
        $this->patchJson($uri, ['apellido_materno' => 'Distinto'])->assertUnprocessable()->assertJsonValidationErrors('apellido_materno');
        $this->patchJson($uri, ['nacionalidad' => 'Distinta'])->assertUnprocessable()->assertJsonValidationErrors('nacionalidad');
        $this->patchJson($uri, ['telefono' => '912345678', 'fecha_nacimiento' => '1990-02-04', 'domicilio' => 'Domicilio actualizado', 'estado_civil' => 'casado'])->assertOk();
        $this->assertDatabaseHas('usuarios', ['id' => $person->id, 'apellido_materno' => 'Flores', 'telefono' => '912345678', 'domicilio' => 'Domicilio actualizado', 'estado_civil' => 'casado']);
        Http::assertNothingSent();
    }

    public function test_contact_edit_preserves_existing_legacy_documents_without_converting_them(): void
    {
        $person = Usuario::factory()->create(['tipo_doc_identidad' => 'pasaporte', 'numero_doc_identidad' => 'PAS-1234']);
        $uri = '/api/v1/expedientes/'.$person->id;
        $this->patchJson($uri, ['telefono' => '912345678'])->assertOk();
        $this->assertSame('pasaporte', $person->refresh()->tipo_doc_identidad);
        $this->assertSame('PAS-1234', $person->numero_doc_identidad);
        $this->patchJson($uri, ['tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => '12345678'])->assertUnprocessable();
    }

    public function test_stale_edit_cannot_overwrite_a_more_recent_update(): void
    {
        $person = Usuario::factory()->create();
        $uri = '/api/v1/expedientes/'.$person->id;
        $version = $this->getJson($uri)->json('data.version');
        $this->withHeader('If-Match', $version)->patchJson($uri, ['telefono' => '912345678'])->assertOk();
        $this->withHeader('If-Match', $version)->patchJson($uri, ['telefono' => '987654321'])->assertConflict();
        $this->assertSame('912345678', $person->refresh()->telefono);
    }

    public function test_edit_and_location_catalog_remain_disabled_in_production(): void
    {
        $person = Usuario::factory()->create();
        $this->app->instance('env', 'production');
        $this->patchJson('/api/v1/expedientes/'.$person->id, ['nombres' => 'No guardar'])->assertForbidden();
        $this->getJson('/api/v1/ubicaciones')->assertForbidden();
        $this->getJson('/api/v1/expediente-opciones')->assertForbidden();
    }
}
