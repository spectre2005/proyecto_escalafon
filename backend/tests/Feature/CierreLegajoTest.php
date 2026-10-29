<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class CierreLegajoTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('legajo.disco_documentos'));
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        for ($seccion = 1; $seccion <= 11; $seccion++) {
            $this->seed('Database\\Seeders\\Seccion'.sprintf('%02d', $seccion).'CatalogoSeeder');
        }
        $this->actuarComo();
        $this->persona = Usuario::factory()->create(['activo' => true]);
        $this->url = '/api/v1/expedientes/'.$this->persona->id;
    }

    private function abrir(): string
    {
        return $this->postJson($this->url.'/legajo')->assertCreated()->json('data.id');
    }

    private function cerrar(string $legajo): void
    {
        $this->postJson($this->url.'/legajos/'.$legajo.'/cerrar')->assertOk()->assertJsonPath('data.estado_legajo', 'pasivo');
    }

    public function test_historical_identity_remains_unchanged_after_new_incorporation_and_profile_edits(): void
    {
        $this->persona->update(['nombres' => 'NOMBRE ANTERIOR', 'domicilio' => 'DIRECCION ANTERIOR', 'email_institucional' => 'anterior@example.test']);
        $id = $this->abrir();
        $this->cerrar($id);
        $this->abrir();
        $this->patchJson($this->url, ['nombres' => 'NOMBRE NUEVO', 'domicilio' => 'DIRECCION NUEVA', 'email_institucional' => 'nuevo@example.test'])->assertOk();
        $this->getJson($this->url.'/legajos/'.$id)->assertOk()
            ->assertJsonPath('data.datos_al_cierre.nombres', 'NOMBRE ANTERIOR')
            ->assertJsonPath('data.datos_al_cierre.domicilio', 'DIRECCION ANTERIOR')
            ->assertJsonPath('data.datos_al_cierre.email_institucional', 'anterior@example.test');
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.usuario.values.nombres', 'NOMBRE NUEVO')
            ->assertJsonMissingPath('data.legajos.0.values.datos_al_cierre');
        $this->getJson('/api/v1/expedientes/legajos-pasivos')->assertOk()->assertJsonPath('data.0.persona.nombres', 'NOMBRE ANTERIOR');
        $raw = Legajo::query()->findOrFail($id)->getRawOriginal('datos_al_cierre');
        $this->assertStringNotContainsString('DIRECCION ANTERIOR', $raw);
        $this->assertArrayNotHasKey('datos_al_cierre', Legajo::query()->findOrFail($id)->toArray());
        $this->assertArrayNotHasKey('password', Legajo::query()->findOrFail($id)->datos_al_cierre);
    }

    public function test_historical_profile_respects_sensitive_data_permission_and_owner(): void
    {
        $this->persona->update(['domicilio' => 'DIRECCION PRIVADA', 'telefono' => '999111222', 'con_discapacidad' => true]);
        $id = $this->abrir();
        $this->cerrar($id);
        config(['permisos.roles.consulta_historial' => ['legajo.ver']]);
        $this->actuarComo(null, 'consulta_historial');
        $this->getJson($this->url.'/legajos/'.$id)->assertOk()
            ->assertJsonMissingPath('data.datos_al_cierre.domicilio')->assertJsonMissingPath('data.datos_al_cierre.telefono')
            ->assertJsonMissingPath('data.datos_al_cierre.con_discapacidad')->assertJsonMissingPath('data.datos_al_cierre.fecha_nacimiento');
        $otro = Usuario::factory()->create();
        $this->getJson('/api/v1/expedientes/'.$otro->id.'/legajos/'.$id)->assertNotFound();
        $this->actuarComo(null, 'rol_sin_permisos');
        $this->getJson($this->url.'/legajos/'.$id)->assertForbidden();
    }

    public function test_legacy_passive_legajo_does_not_claim_current_data_as_historical(): void
    {
        $legajo = Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'pasivo']);
        $this->getJson($this->url.'/legajos/'.$legajo->id)->assertOk()->assertJsonPath('data.datos_al_cierre', null);
    }

    public function test_active_status_follows_opening_and_closing_and_is_not_manually_editable(): void
    {
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.usuario.values.activo', false);
        $id = $this->abrir();
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.usuario.values.activo', true);
        $this->patchJson($this->url, ['activo' => false])->assertUnprocessable()->assertJsonValidationErrors('activo');
        $this->cerrar($id);
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.usuario.values.activo', false);
        $this->assertSame(today()->toDateString(), Legajo::query()->findOrFail($id)->fecha_pasivo->toDateString());
        $this->assertTrue($this->persona->refresh()->activo);
        $this->postJson($this->url.'/legajos/'.$id.'/cerrar')->assertConflict();
    }

    public function test_closed_legajo_keeps_all_sections_and_pdf_downloads_available(): void
    {
        $id = $this->abrir();
        $tipo = TipoDocumento::query()->where('codigo', 'S01_A_FICHA_DATOS')->firstOrFail();
        $documento = $this->subir($this->persona, $this->metadatos($tipo, 1, 2))->assertCreated()->json('data.id');
        foreach ([3, 4, 6, 8] as $seccion) {
            $tipoSeccion = TipoDocumento::query()->where('seccion_legajo_id', $seccion)->where('activo', true)->firstOrFail();
            DocumentoUsuario::factory()->create(['usuario_id' => $this->persona->id, 'legajo_id' => $id,
                'seccion_legajo_id' => $seccion, 'tipo_documento_id' => $tipoSeccion->id, 'estado' => 'cargado']);
        }
        $antes = [];
        for ($seccion = 1; $seccion <= 11; $seccion++) {
            $antes[$seccion] = $this->getJson($this->url.'/seccion-'.sprintf('%02d', $seccion))->assertOk()->json('data');
        }
        $this->cerrar($id);
        foreach ($antes as $seccion => $datos) {
            $despues = $this->getJson($this->url.'/seccion-'.sprintf('%02d', $seccion))->assertOk()
                ->assertJsonPath('data.legajo.id', $id)->assertJsonPath('data.legajo.estado_legajo', 'pasivo')->json('data');
            foreach (['tipos', 'items', 'subsecciones'] as $campo) {
                if (isset($datos[$campo])) {
                    $this->assertEquals($datos[$campo], $despues[$campo], 'Los documentos y datos de la sección se conservan tras el cierre.');
                }
            }
        }
        $this->get($this->url.'/documentos/'.$documento.'/archivo?accion=ver')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($this->url.'/documentos/'.$documento.'/archivo?accion=descargar')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_closed_legajo_rejects_all_section_mutations_and_photo_and_general_edits(): void
    {
        $id = $this->abrir();
        $this->cerrar($id);
        $uuid = '00000000-0000-4000-8000-000000000001';
        $this->patchJson($this->url, ['nombres' => 'Cambio no permitido'])->assertConflict();
        foreach (['foto', 'familiares', 'declaraciones-verificaciones', 'certificados-discapacidad', 'vinculos-laborales',
            'documentos', 'seccion-03/documentos', 'seccion-04/documentos', 'seccion-05/licencias-permisos', 'seccion-06/documentos',
            'seccion-07/evaluaciones-desempeno', 'seccion-08/documentos', 'seccion-09/controversias', 'seccion-10/documentos', 'seccion-11/desvinculaciones',
            'documentos/'.$uuid.'/recepcionar', 'seccion-03/documentos/'.$uuid.'/estado', 'seccion-10/documentos/'.$uuid.'/anular'] as $ruta) {
            $this->postJson($this->url.'/'.$ruta)->assertConflict();
        }
        $this->assertDatabaseCount('documentos_usuario', 0);
        $this->assertDatabaseCount('archivos_documento', 0);
        $this->assertNotSame('Cambio no permitido', $this->persona->refresh()->nombres);
    }

    public function test_closure_checks_permission_and_document_owner(): void
    {
        $id = $this->abrir();
        $otro = Usuario::factory()->create();
        $this->postJson('/api/v1/expedientes/'.$otro->id.'/legajos/'.$id.'/cerrar')->assertNotFound();
        $this->actuarComo(null, 'asistente_legajo');
        $this->postJson($this->url.'/legajos/'.$id.'/cerrar')->assertOk();
        $this->actuarComo(null, 'rol_sin_permisos');
        $this->patchJson($this->url, ['nombres' => 'Cambio sin permiso'])->assertForbidden();
        $nuevo = Legajo::factory()->create(['usuario_id' => $otro->id]);
        $this->postJson('/api/v1/expedientes/'.$otro->id.'/legajos/'.$nuevo->id.'/cerrar')->assertForbidden();
    }

    public function test_new_active_legajo_does_not_allow_changing_documents_of_the_closed_legajo(): void
    {
        $id = $this->abrir();
        $tipo = TipoDocumento::query()->where('codigo', 'S01_A_FICHA_DATOS')->firstOrFail();
        $documento = $this->subir($this->persona, $this->metadatos($tipo, 1, 2))->assertCreated()->json('data.id');
        $this->cerrar($id);
        $this->abrir();
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.usuario.values.activo', true);
        $this->postJson($this->url.'/documentos/'.$documento.'/recepcionar')->assertConflict();
        $this->patchJson($this->url, ['nombres' => 'Nombre actualizado'])->assertOk();
        $this->assertNull(DocumentoUsuario::query()->findOrFail($documento)->fecha_recepcion);
    }

    public function test_closed_legajo_stays_in_passive_archive_when_person_gets_a_new_legajo(): void
    {
        $id = $this->abrir();
        $anterior = Legajo::query()->findOrFail($id);
        $tipo = TipoDocumento::query()->where('codigo', 'S01_A_FICHA_DATOS')->firstOrFail();
        $documento = $this->subir($this->persona, $this->metadatos($tipo, 1, 2))->assertCreated()->json('data.id');
        $this->cerrar($id);
        $this->postJson($this->url.'/legajos/'.$id.'/reabrir')->assertNotFound();
        $nuevo = $this->abrir();
        $this->assertNotSame($id, $nuevo);
        $this->assertNotSame($anterior->numero_legajo, Legajo::query()->findOrFail($nuevo)->numero_legajo);
        $this->assertSame('pasivo', $anterior->refresh()->estado_legajo);
        $this->assertNotNull($anterior->fecha_pasivo);
        $this->assertSame($id, DocumentoUsuario::query()->findOrFail($documento)->legajo_id);
        $this->assertSame(0, DocumentoUsuario::query()->where('legajo_id', $nuevo)->count());
        Storage::disk(config('legajo.disco_documentos'))->assertExists(DocumentoUsuario::query()->findOrFail($documento)->archivo->storage_path);
        $this->getJson('/api/v1/expedientes/legajos-pasivos?'.http_build_query(['q' => $anterior->numero_legajo]))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/expedientes/legajos-pasivos/resumen')->assertOk()->assertJsonPath('data.total', 1);
        $this->get($this->url.'/documentos/'.$documento.'/archivo?accion=ver')->assertOk();
        $this->postJson($this->url.'/documentos/'.$documento.'/recepcionar')->assertConflict();
        $this->assertDatabaseMissing('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'legajo.reabrir']);
    }
}
