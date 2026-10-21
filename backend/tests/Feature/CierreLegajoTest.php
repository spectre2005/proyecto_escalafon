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
        $this->patchJson($this->url, ['telefono' => '912345678'])->assertOk();
        $this->assertNull(DocumentoUsuario::query()->findOrFail($documento)->fecha_recepcion);
    }

    public function test_reopening_keeps_the_same_legajo_and_documents_and_resumes_edits_and_folios(): void
    {
        $id = $this->abrir();
        $legajo = Legajo::query()->findOrFail($id);
        $tipo = TipoDocumento::query()->where('codigo', 'S01_A_FICHA_DATOS')->firstOrFail();
        $documento = $this->subir($this->persona, $this->metadatos($tipo, 1, 2))->assertCreated()->json('data.id');
        $this->cerrar($id);
        $this->postJson($this->url.'/legajos/'.$id.'/reabrir')->assertOk()
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.numero_legajo', $legajo->numero_legajo)
            ->assertJsonPath('data.estado_legajo', 'activo')->assertJsonPath('data.fecha_pasivo', null);
        $this->assertDatabaseCount('legajos', 1);
        $this->assertSame($legajo->fecha_apertura->toDateString(), $legajo->refresh()->fecha_apertura->toDateString());
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.usuario.values.activo', true);
        $this->patchJson($this->url, ['telefono' => '912345678'])->assertOk();
        $this->postJson($this->url.'/documentos/'.$documento.'/recepcionar')->assertOk();
        $this->subir($this->persona, $this->metadatos($tipo, 3, 4, ['subsana_a_documento_id' => $documento]))
            ->assertCreated()->assertJsonPath('data.folio_inicio', 3);
        Storage::disk(config('legajo.disco_documentos'))->assertExists(DocumentoUsuario::query()->findOrFail($documento)->archivo->storage_path);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'legajo.cerrar']);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'legajo.reabrir']);
    }

    public function test_reopening_checks_owner_permission_and_prevents_two_active_legajos(): void
    {
        $id = $this->abrir();
        $this->postJson($this->url.'/legajos/'.$id.'/reabrir')->assertConflict();
        $this->cerrar($id);
        $otro = Usuario::factory()->create();
        $this->postJson('/api/v1/expedientes/'.$otro->id.'/legajos/'.$id.'/reabrir')->assertNotFound();
        $this->actuarComo(null, 'asistente_legajo');
        $this->postJson($this->url.'/legajos/'.$id.'/reabrir')->assertForbidden();
        $this->actuarComo();
        $this->abrir();
        $this->postJson($this->url.'/legajos/'.$id.'/reabrir')->assertConflict();
        $this->assertSame('pasivo', Legajo::query()->findOrFail($id)->estado_legajo);
        $this->assertSame(1, Legajo::query()->where('usuario_id', $this->persona->id)->where('estado_legajo', 'activo')->count());
    }
}
