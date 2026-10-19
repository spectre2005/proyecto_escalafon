<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\Familiar;
use App\Models\PerfilAdministrativo;
use App\Models\RegimenNormativo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\AplicacionDocumental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/**
 * Auditoría de las escrituras que antes no dejaban evento (alta, apertura, vínculo, carga genérica, recepción, familiares,
 * anulación, ficha, borrado) y correcciones de la evaluación del alta de personal del 19/10/2026.
 */
class AuditoriaRegistrosTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararDemo();
    }

    private function evento(string $accion, string $entidadId): AuditoriaEvento
    {
        return AuditoriaEvento::query()->where('accion', $accion)->where('entidad_id', $entidadId)->sole();
    }

    public function test_person_registration_and_registration_with_link_are_audited_without_password(): void
    {
        $actor = $this->actuarComo();
        $ley = RegimenNormativo::factory()->create(['aplica_a' => 'administrativo'])->id;
        $persona = fn (string $dni) => ['numero_doc_identidad' => $dni, 'nombres' => 'Ana', 'apellido_paterno' => 'Prueba', 'email_institucional' => "a$dni@example.test",
            'tipo_personal' => 'administrativo', 'regimen_normativo_id' => $ley];
        $id = $this->postJson('/api/v1/usuarios', ['usuarios' => [$persona('71000101')]])->assertCreated()->json('data.0.id');
        $alta = $this->evento('persona.alta', $id);
        $this->assertSame([$actor->id, $id], [$alta->actor_usuario_id, $alta->usuario_titular_id]);
        $this->assertArrayNotHasKey('password', json_decode($alta->datos_despues, true));

        // Con vínculo: los datos del puesto administrativo pueden venir vacíos (todos opcionales) y no crean un perfil vacío.
        $datos = $this->postJson('/api/v1/usuarios/con-vinculo', ['usuarios' => [$persona('71000102')],
            'vinculo' => ['regimen_laboral' => 'plazo_determinado', 'nombre_puesto' => 'Asistente', 'fecha_inicio' => '2026-03-01'], 'datos_puesto' => []])
            ->assertCreated()->json('data');
        $this->assertSame(0, PerfilAdministrativo::query()->where('vinculo_laboral_id', $datos['vinculo']['id'])->count());
        $this->evento('persona.alta', $datos['usuario']['id']);
        $this->assertSame($datos['usuario']['id'], $this->evento('legajo.abrir', $datos['legajo']['id'])->usuario_titular_id);
        $this->assertNull(json_decode($this->evento('vinculo.registrar', $datos['vinculo']['id'])->datos_despues, true)['datos_puesto']);
    }

    public function test_manual_opening_generic_upload_and_reception_are_audited(): void
    {
        $actor = $this->actuarComo();
        $sinLegajo = Usuario::factory()->create();
        $legajo = $this->postJson('/api/v1/expedientes/'.$sinLegajo->id.'/legajo')->assertCreated()->json('data.id');
        $this->assertSame($sinLegajo->id, $this->evento('legajo.abrir', $legajo)->usuario_titular_id);

        $persona = $this->personaDemo();
        $id = $this->subir($persona, $this->metadatos($this->tipo02('a'), 1, 1))->assertCreated()->json('data.id');
        $this->assertSame($persona->id, $this->evento('documento.creado', $id)->usuario_titular_id);
        $this->postJson('/api/v1/expedientes/'.$persona->id.'/documentos/'.$id.'/recepcionar')->assertOk();
        $recepcion = $this->evento('documento.recepcionado', $id);
        $this->assertSame($actor->id, json_decode($recepcion->datos_despues, true)['recepcionado_por']);
        $this->assertSame($this->evento('documento.creado', $id)->hash_registro, $recepcion->hash_previo);
        // Una recepción repetida no deja otro evento.
        $this->postJson('/api/v1/expedientes/'.$persona->id.'/documentos/'.$id.'/recepcionar')->assertConflict();
        $this->assertSame(1, AuditoriaEvento::query()->where('accion', 'documento.recepcionado')->count());
    }

    public function test_the_holder_of_a_servant_document_must_be_the_person_of_the_file(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo();
        $this->subir($persona, $this->metadatos($this->tipo02('a'), 1, 1, ['titular_documento' => '11111111']))
            ->assertUnprocessable()->assertJsonValidationErrors('titular_documento');
        // De otra relación (un familiar), el titular es otra persona.
        $this->subir($persona, $this->metadatos($this->tipo02('a'), 1, 1, ['titular_nombre' => 'Familiar', 'titular_documento' => '11111111', 'relacion_titular' => 'otro']))
            ->assertCreated();
    }

    public function test_relatives_annulment_and_file_edits_are_audited_with_only_what_changed(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo();
        $url = '/api/v1/expedientes/'.$persona->id;
        $familiar = $this->postJson($url.'/familiares', ['apellidos_nombres' => 'Hijo Prueba', 'parentesco' => 'hijo', 'es_derechohabiente' => false])->assertCreated()->json('data.id');
        $this->evento('familiar.registrar', $familiar);
        $this->patchJson($url.'/familiares/'.$familiar, ['apellidos_nombres' => 'Hijo Corregido'])->assertOk();
        $this->assertSame(['apellidos_nombres' => 'Hijo Corregido'], json_decode($this->evento('familiar.corregir', $familiar)->datos_despues, true));
        $this->postJson($url.'/familiares/'.$familiar.'/anular', ['motivo_anulacion' => 'Registrado por error'])->assertOk();
        $anulacion = $this->evento('familiares.anular', $familiar);
        $this->assertSame([$persona->id, 'Registrado por error'], [$anulacion->usuario_titular_id, json_decode($anulacion->datos_despues, true)['motivo_anulacion']]);
        $this->assertTrue(Familiar::query()->findOrFail($familiar)->anulado);

        $this->patchJson($url, ['telefono' => '987654321'])->assertOk();
        $ficha = $this->evento('persona.editar', $persona->id);
        $this->assertSame(['telefono' => '987654321'], json_decode($ficha->datos_despues, true));
    }

    public function test_generic_crud_writes_are_closed_in_local_too(): void
    {
        $this->actuarComo();
        $this->app['env'] = 'local';
        try {
            $this->deleteJson('/api/v1/formacion-academica/'.fake()->uuid())->assertForbidden();
            $this->getJson('/api/v1/formacion-academica')->assertOk();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_without_sensitive_access_the_file_does_not_offer_sensitive_listings(): void
    {
        $persona = $this->personaDemo();
        $this->actuarComo(codigo: 'asistente_legajo');
        $secciones = collect($this->getJson('/api/v1/expedientes/'.$persona->id)->assertOk()->json('data.sections'))->pluck('key');
        $this->assertNotContains('familiares', $secciones);
        $this->assertNotContains('registros-sst-bienestar', $secciones);
        $this->assertContains('vinculos-laborales', $secciones);
        $this->actuarComo();
        $this->assertContains('familiares', collect($this->getJson('/api/v1/expedientes/'.$persona->id)->json('data.sections'))->pluck('key'));
    }

    public function test_link_type_dependent_documents_are_pending_definition_without_a_known_link_type(): void
    {
        $soloCarrera = new TipoDocumento(['codigo' => 'X_PRUEBA', 'aplica_carrera' => true, 'aplica_plazo_determinado' => false, 'aplica_designado' => false]);
        $sinVinculo = Usuario::factory()->create();
        $this->assertStringContainsString('aún no hay un vínculo laboral', (string) AplicacionDocumental::para($sinVinculo)->porDefinir($soloCarrera));
        VinculoLaboral::factory()->create(['usuario_id' => $sinVinculo->id, 'regimen_laboral' => null]);
        $this->assertStringContainsString('Sin definir', (string) AplicacionDocumental::para($sinVinculo->refresh())->porDefinir($soloCarrera));
        $conCarrera = Usuario::factory()->create();
        VinculoLaboral::factory()->create(['usuario_id' => $conCarrera->id, 'regimen_laboral' => 'carrera']);
        $this->assertNull(AplicacionDocumental::para($conCarrera)->porDefinir($soloCarrera));
    }
}
