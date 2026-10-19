<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Support\Documentos\EstadoDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class DocumentoRecepcionVisorTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararDemo();
    }

    private function documentoSubido(string $literal = 'a'): DocumentoUsuario
    {
        $this->actuarComo();
        $id = $this->subir($this->personaDemo(), $this->metadatos($this->tipo02($literal), 1, 2))->assertCreated()->json('data.id');

        return DocumentoUsuario::query()->findOrFail($id);
    }

    private function url(DocumentoUsuario $documento, string $accion): string
    {
        return '/api/v1/expedientes/'.$documento->usuario_id.'/documentos/'.$documento->id.'/'.$accion;
    }

    public function test_direct_acceptance_is_internal_and_never_approves_an_infected_or_missing_file(): void
    {
        $documento = $this->documentoSubido();
        $documento->tipoDocumento->update(['acredita_perfil_puesto' => true]);
        $this->assertSame('aceptado', $documento->estado);
        $this->assertTrue(EstadoDocumento::aprobado($documento, $documento->tipoDocumento));
        $this->assertFalse($documento->fiscalizado);
        $this->assertNull($documento->fecha_recepcion);
        $documento->archivo->update(['estado_antivirus' => 'infectado']);
        $this->assertFalse(EstadoDocumento::aprobado($documento->fresh(), $documento->tipoDocumento));
        $documento->update(['archivo_id' => null]);
        $this->assertFalse(EstadoDocumento::aprobado($documento->fresh(), $documento->tipoDocumento));
    }

    public function test_reception_records_who_and_when_and_is_required_for_approval(): void
    {
        $documento = $this->documentoSubido();
        $this->documentoAnteriorPendiente($documento->id, 'cargado');
        $documento->refresh();
        $this->assertFalse(EstadoDocumento::aprobado($documento, $documento->tipoDocumento));
        $persona = $this->personaDemo();
        $this->getJson('/api/v1/expedientes/'.$persona->id.'/seccion-02')->assertOk()
            ->assertJsonPath('data.subsecciones.0.tipos.0.estado', 'cargado')->assertJsonPath('data.subsecciones.0.tipos.0.recepcionado', false)
            ->assertJsonPath('data.subsecciones.0.tipos.0.lista.0.aprobado', false);

        $recepciona = $this->actuarComo();
        $this->postJson($this->url($documento, 'recepcionar'))->assertOk()
            ->assertJsonPath('data.estado', 'cargado')->assertJsonPath('data.estado_visible', 'recepcionado')
            ->assertJsonPath('data.recepcionado_por', $recepciona->id)->assertJsonPath('data.fecha_recepcion', today()->toDateString())
            ->assertJsonPath('data.aprobado', true);
        $documento->refresh();
        $this->assertSame([$recepciona->id, today()->toDateString()], [$documento->recepcionado_por, $documento->fecha_recepcion->toDateString()]);
        // Una sola vez: la segunda no pisa a quien recepcionó.
        $this->actuarComo();
        $this->postJson($this->url($documento, 'recepcionar'))->assertConflict();
        $this->assertSame($recepciona->id, $documento->refresh()->recepcionado_por);
        $this->getJson('/api/v1/expedientes/'.$persona->id.'/seccion-02')->assertOk()
            ->assertJsonPath('data.subsecciones.0.tipos.0.estado', 'recepcionado')->assertJsonPath('data.subsecciones.0.tipos.0.recepcionado', true)
            ->assertJsonPath('data.subsecciones.0.tipos.0.lista.0.recepcionado_por', trim($recepciona->nombres.' '.$recepciona->apellido_paterno.' '.$recepciona->apellido_materno));
    }

    public function test_types_that_accredit_the_job_profile_also_need_audit_to_be_approved(): void
    {
        $documento = $this->documentoSubido();
        $this->documentoAnteriorPendiente($documento->id, 'cargado');
        $documento->refresh();
        $documento->tipoDocumento->acredita_perfil_puesto = true;
        $documento->update(['fecha_recepcion' => today(), 'recepcionado_por' => $documento->created_by]);

        $this->assertFalse(EstadoDocumento::aprobado($documento, $documento->tipoDocumento));
        $documento->forceFill(['fiscalizado' => true, 'estado' => 'verificado']);
        $this->assertTrue(EstadoDocumento::aprobado($documento, $documento->tipoDocumento));
    }

    public function test_reception_requires_permission(): void
    {
        $documento = $this->documentoSubido();
        config(['permisos.roles.SOLO_SUBE' => ['legajo.ver', 'documento.subir']]);
        $this->actuarComo(null, 'SOLO_SUBE');

        $this->postJson($this->url($documento, 'recepcionar'))->assertForbidden();
        $this->assertNull($documento->refresh()->fecha_recepcion);
    }

    public function test_viewer_streams_the_pdf_and_logs_each_access(): void
    {
        $documento = $this->documentoSubido();
        $lector = $this->actuarComo();

        $respuesta = $this->get($this->url($documento, 'archivo'), ['User-Agent' => 'Navegador de prueba'])->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline', $respuesta->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $respuesta->streamedContent());
        $this->assertDatabaseHas('bitacora_accesos_legajo', ['legajo_id' => $documento->legajo_id, 'documento_id' => $documento->id, 'usuario_id' => $lector->id,
            'accion' => 'ver', 'ip_origen' => '127.0.0.1', 'user_agent' => 'Navegador de prueba']);

        $descarga = $this->get($this->url($documento, 'archivo').'?accion=descargar')->assertOk();
        $this->assertStringStartsWith('attachment', $descarga->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('bitacora_accesos_legajo', ['documento_id' => $documento->id, 'accion' => 'descargar']);
        $this->assertDatabaseCount('bitacora_accesos_legajo', 2);
        $this->getJson($this->url($documento, 'archivo').'?accion=borrar')->assertUnprocessable();
        // Ningún PDF del legajo queda bajo public/.
        $this->assertDirectoryDoesNotExist(public_path('storage/legajos'));
    }

    public function test_viewer_without_permission_is_forbidden_and_not_logged(): void
    {
        $documento = $this->documentoSubido();
        config(['permisos.roles.SIN_LEGAJO' => ['personal.ver']]);
        $this->actuarComo(null, 'SIN_LEGAJO');

        $this->get($this->url($documento, 'archivo'))->assertForbidden();
        $this->assertDatabaseCount('bitacora_accesos_legajo', 0);
    }

    public function test_viewer_rejects_documents_of_another_person(): void
    {
        $documento = $this->documentoSubido();
        $otra = $this->personaDemo('99000202');

        $this->get('/api/v1/expedientes/'.$otra->id.'/documentos/'.$documento->id.'/archivo')->assertNotFound();
        $this->postJson('/api/v1/expedientes/'.$otra->id.'/documentos/'.$documento->id.'/recepcionar')->assertNotFound();
        $this->assertDatabaseCount('bitacora_accesos_legajo', 0);
    }
}
