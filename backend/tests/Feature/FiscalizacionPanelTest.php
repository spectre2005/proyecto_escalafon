<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\Legajo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\OperacionesAdministrativas;
use Tests\TestCase;

class FiscalizacionPanelTest extends TestCase
{
    use OperacionesAdministrativas;
    use RefreshDatabase;

    private Usuario $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->prepararOperaciones();
    }

    private function crearCaso(Legajo $legajo): array
    {
        return $this->postJson('/api/v1/fiscalizacion/casos', ['legajo_id' => $legajo->id, 'tipo_muestra' => 'aleatoria', 'fecha_vinculacion' => $this->fechaOperacion(-5), 'fecha_inicio' => $this->fechaOperacion(), 'fecha_limite' => $this->fechaOperacion(15)])
            ->assertCreated()->assertJsonPath('data.responsable_id', $this->actor->id)->json('data');
    }

    private function accion(array $caso, string $accion, array $datos = []): TestResponse
    {
        return $this->postJson('/api/v1/fiscalizacion/casos/'.$caso['id'].'/acciones', ['version' => $caso['version'], 'accion' => $accion, ...$datos]);
    }

    public function test_complete_case_keeps_evidence_updates_the_document_and_can_be_reopened_with_a_reason(): void
    {
        $legajo = $this->legajoOperativo();
        $doc = $this->documentoOperativo($legajo);
        $oficio = $this->documentoOperativo($legajo);
        $informe = $this->documentoOperativo($legajo);
        $caso = $this->crearCaso($legajo);
        $caso = $this->accion($caso, 'comunicar', ['documento_id' => $doc->id, 'oficio_documento_id' => $oficio->id, 'entidad_consultada' => 'Universidad de prueba', 'medio' => 'mesa_partes', 'numero_oficio' => 'OF-001', 'fecha_envio' => $this->fechaOperacion()])->assertOk()->json('data');
        $detalle = $this->getJson('/api/v1/fiscalizacion/casos/'.$caso['id'])->assertOk()->json('data');
        $solicitud = $detalle['solicitudes_fiscalizacion'][0];
        $this->accion($caso, 'cerrar', ['informe_documento_id' => $informe->id, 'conclusiones' => 'Falta respuesta', 'fecha_fin' => $this->fechaOperacion()])->assertConflict();
        $caso = $this->accion($caso, 'responder', ['solicitud_id' => $solicitud['id'], 'documento_respuesta_id' => $oficio->id, 'fecha_respuesta' => $this->fechaOperacion(), 'resultado' => 'conforme', 'descripcion_hallazgo' => 'La entidad confirma autenticidad.'])->assertOk()->json('data');
        $caso = $this->accion($caso, 'cerrar', ['informe_documento_id' => $informe->id, 'fecha_fin' => $this->fechaOperacion(), 'conclusiones' => 'Verificación conforme sustentada.'])->assertOk()->assertJsonPath('data.estado', 'cerrado')->assertJsonPath('data.resultado', 'conforme')->json('data');
        $this->assertSame('verificado', $doc->fresh()->estado);
        $this->assertTrue($doc->fresh()->fiscalizado);
        $this->accion($caso, 'reabrir')->assertUnprocessable();
        $this->accion($caso, 'reabrir', ['motivo' => 'Nueva evidencia recibida'])->assertOk()->assertJsonPath('data.estado', 'abierto');
        $this->getJson('/api/v1/operaciones/historial/procesos_fiscalizacion/'.$caso['id'])->assertOk()->assertJsonPath('total', 5);
        $eventos = AuditoriaEvento::query()->where('entidad', 'procesos_fiscalizacion')->where('entidad_id', $caso['id'])->orderBy('id')->get();
        $this->assertSame($eventos[0]->hash_registro, $eventos[1]->hash_previo);
        $this->deleteJson('/api/v1/procesos-fiscalizacion/'.$caso['id'])->assertMethodNotAllowed();
    }

    public function test_wrong_documents_dates_closed_legajos_and_stale_versions_are_rejected(): void
    {
        $legajo = $this->legajoOperativo();
        $caso = $this->crearCaso($legajo);
        $ajeno = $this->documentoOperativo($this->legajoOperativo());
        $this->accion($caso, 'comunicar', ['documento_id' => $ajeno->id, 'oficio_documento_id' => $ajeno->id, 'entidad_consultada' => 'Prueba', 'medio' => 'correo', 'numero_oficio' => 'OF-002', 'fecha_envio' => $this->fechaOperacion()])->assertNotFound();
        $this->accion($caso, 'prorrogar', ['fecha_limite' => $this->fechaOperacion(10), 'motivo' => 'No amplía'])->assertUnprocessable();
        $nuevo = $this->accion($caso, 'prorrogar', ['fecha_limite' => $this->fechaOperacion(20), 'motivo' => 'Ampliación sustentada'])->assertOk()->json('data');
        $this->accion($caso, 'cancelar', ['motivo' => 'Versión antigua'])->assertConflict();
        $this->accion($nuevo, 'cancelar', ['motivo' => 'Solicitud duplicada'])->assertOk();
        $legajo->update(['estado_legajo' => 'pasivo']);
        $this->postJson('/api/v1/fiscalizacion/casos', ['legajo_id' => $legajo->id, 'tipo_muestra' => 'directa', 'fecha_vinculacion' => $this->fechaOperacion(-5), 'fecha_inicio' => $this->fechaOperacion(), 'fecha_limite' => $this->fechaOperacion(15)])->assertConflict();
    }

    public function test_assistant_cannot_open_cases_or_read_administrative_history(): void
    {
        $legajo = $this->legajoOperativo();
        $caso = $this->crearCaso($legajo);
        $this->actuarComo(null, 'asistente_legajo');
        $this->getJson('/api/v1/fiscalizacion/casos')->assertForbidden();
        $this->getJson('/api/v1/fiscalizacion/casos/'.$caso['id'])->assertForbidden();
        $this->getJson('/api/v1/operaciones/historial/procesos_fiscalizacion/'.$caso['id'])->assertForbidden();
        $this->postJson('/api/v1/fiscalizacion/casos', [])->assertForbidden();
        Sanctum::actingAs(Usuario::factory()->create());
        $this->getJson('/api/v1/operaciones/legajos')->assertForbidden();
    }

    public function test_cannot_fiscalize_ones_own_uploaded_document(): void
    {
        $legajo = $this->legajoOperativo();
        $doc = $this->documentoOperativo($legajo);
        $doc->update(['created_by' => $this->actor->id]);
        $caso = $this->crearCaso($legajo);
        $caso = $this->accion($caso, 'comunicar', ['documento_id' => $doc->id, 'oficio_documento_id' => $doc->id, 'entidad_consultada' => 'Entidad', 'medio' => 'correo', 'numero_oficio' => 'OF-003', 'fecha_envio' => $this->fechaOperacion()])->assertOk()->json('data');
        $solicitud = $this->getJson('/api/v1/fiscalizacion/casos/'.$caso['id'])->json('data.solicitudes_fiscalizacion.0.id');
        $caso = $this->accion($caso, 'responder', ['solicitud_id' => $solicitud, 'documento_respuesta_id' => $doc->id, 'fecha_respuesta' => $this->fechaOperacion(), 'resultado' => 'conforme', 'descripcion_hallazgo' => 'Verificado'])->assertOk()->json('data');
        $this->accion($caso, 'cerrar', ['informe_documento_id' => $doc->id, 'fecha_fin' => $this->fechaOperacion(), 'conclusiones' => 'No debe admitirse'])->assertForbidden();
        $this->assertSame('cargado', $doc->fresh()->estado);
    }

    public function test_uploaded_types_are_scoped_to_legajo_and_saved_as_the_case_document_type(): void
    {
        $legajo = $this->legajoOperativo();
        $doc = $this->documentoOperativo($legajo);
        $doc->tipoDocumento->update(['nombre' => 'Certificado de experiencia profesional y funciones acreditadas', 'activo' => true]);
        $ajeno = $this->documentoOperativo($this->legajoOperativo());
        $anulado = $this->documentoOperativo($legajo);
        $anulado->update(['estado' => 'anulado']);
        $r = $this->getJson('/api/v1/fiscalizacion/tipos?legajo_id='.$legajo->id)->assertOk()->assertJsonCount(1, 'data');
        $r->assertJsonPath('data.0.id', $doc->tipo_documento_id)->assertJsonPath('data.0.cantidad', 1);
        $datos = ['legajo_id' => $legajo->id, 'tipo_documento_id' => $doc->tipo_documento_id,
            'fecha_vinculacion' => $this->fechaOperacion(-5), 'fecha_inicio' => $this->fechaOperacion(), 'fecha_limite' => $this->fechaOperacion(15)];
        $this->postJson('/api/v1/fiscalizacion/casos', [...$datos, 'tipo_documento_id' => $ajeno->tipo_documento_id])->assertUnprocessable()->assertJsonValidationErrors('tipo_documento_id');
        $caso = $this->postJson('/api/v1/fiscalizacion/casos', $datos)->assertCreated()->assertJsonPath('data.tipo_documento_id', $doc->tipo_documento_id)->json('data');
        $this->getJson('/api/v1/fiscalizacion/casos/'.$caso['id'])->assertOk()->assertJsonPath('data.tipo_documento.nombre', $doc->tipoDocumento->nombre);
        $this->getJson('/api/v1/operaciones/legajos/'.$legajo->id.'/documentos?tipo_documento_id='.$doc->tipo_documento_id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $doc->id);
        $oficio = $this->documentoOperativo($legajo);
        $comunicacion = ['documento_id' => $oficio->id, 'oficio_documento_id' => $oficio->id, 'entidad_consultada' => 'Entidad de prueba',
            'medio' => 'mesa_partes', 'numero_oficio' => 'OF-002', 'fecha_envio' => $this->fechaOperacion()];
        $this->accion($caso, 'comunicar', $comunicacion)->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $this->accion($caso, 'comunicar', [...$comunicacion, 'documento_id' => $doc->id])->assertOk();
        $this->assertDatabaseHas('solicitudes_fiscalizacion', ['proceso_fiscalizacion_id' => $caso['id'], 'documento_id' => $doc->id]);
    }

    public function test_unavailable_uploaded_type_cannot_open_a_case_and_assistant_cannot_access_type_selector(): void
    {
        $legajo = $this->legajoOperativo();
        $doc = $this->documentoOperativo($legajo);
        $doc->update(['estado' => 'anulado']);
        $this->getJson('/api/v1/fiscalizacion/tipos?legajo_id='.$legajo->id)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/fiscalizacion/casos', ['legajo_id' => $legajo->id, 'tipo_documento_id' => $doc->tipo_documento_id,
            'fecha_vinculacion' => $this->fechaOperacion(-5), 'fecha_inicio' => $this->fechaOperacion(), 'fecha_limite' => $this->fechaOperacion(15)])->assertUnprocessable();
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson('/api/v1/fiscalizacion/tipos?legajo_id='.$legajo->id)->assertForbidden();
    }
}
