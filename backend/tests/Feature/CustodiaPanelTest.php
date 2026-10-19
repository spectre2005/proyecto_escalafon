<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\OperacionesAdministrativas;
use Tests\TestCase;

class CustodiaPanelTest extends TestCase
{
    use OperacionesAdministrativas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararOperaciones();
    }

    private function solicitud(DocumentoUsuario $doc): array
    {
        return $this->postJson('/api/v1/custodia/solicitudes', ['legajo_id' => $doc->legajo_id, 'solicitante_nombre' => 'Persona de prueba', 'solicitante_documento' => 'TEST-001', 'tipo_solicitante' => 'externo',
            'detalle_requerido' => 'Constancia específica', 'sustento' => 'Solicitud formal de prueba', 'forma_entrega' => 'prestamo', 'incluye_datos_sensibles' => false, 'compromiso_confidencialidad' => true, 'documentos_autorizados' => [$doc->id]])
            ->assertCreated()->assertJsonPath('data.estado', 'pendiente')->json('data');
    }

    private function accion(array $solicitud, string $accion, array $datos = []): TestResponse
    {
        return $this->postJson('/api/v1/custodia/solicitudes/'.$solicitud['id'].'/acciones', ['accion' => $accion, 'version' => $solicitud['version'], 'motivo' => 'Decisión sustentada', ...$datos]);
    }

    private function prestar(array $solicitud): TestResponse
    {
        return $this->postJson('/api/v1/custodia/solicitudes/'.$solicitud['id'].'/prestamos', ['fecha_movimiento' => $this->fechaOperacion(), 'fecha_devolucion_prevista' => $this->fechaOperacion(2),
            'entidad_destino' => 'Oficina de prueba, responsable Persona de prueba', 'numero_documento_sustento' => 'ACTA-001', 'numero_inventario' => 'INV-001', 'formato_legajo' => 'fisico', 'observaciones' => 'Entrega del alcance autorizado']);
    }

    public function test_approval_limits_document_delivery_and_revocation_blocks_further_deliveries(): void
    {
        $legajo = $this->legajoOperativo();
        $doc = $this->documentoOperativo($legajo);
        $otro = $this->documentoOperativo($legajo);
        $solicitud = $this->solicitud($doc);
        $base = '/api/v1/custodia/solicitudes/'.$solicitud['id'].'/documentos/';
        $this->getJson($base.$doc->id.'/archivo')->assertForbidden();
        $solicitud = $this->accion($solicitud, 'aprobar', ['vigente_hasta' => $this->fechaOperacion(7)])->assertOk()->json('data');
        $this->get($base.$doc->id.'/archivo')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson($base.$otro->id.'/archivo')->assertForbidden();
        $this->assertDatabaseHas('bitacora_accesos_legajo', ['solicitud_acceso_id' => $solicitud['id'], 'documento_id' => $doc->id, 'accion' => 'entrega']);
        $this->accion($solicitud, 'revocar')->assertOk();
        $this->getJson($base.$doc->id.'/archivo')->assertForbidden();
        $this->getJson('/api/v1/operaciones/historial/solicitudes_acceso_legajo/'.$solicitud['id'])->assertOk()->assertJsonPath('total', 3);
    }

    public function test_loans_prevent_duplicates_and_return_is_allowed_after_authorization_expires(): void
    {
        $doc = $this->documentoOperativo($this->legajoOperativo());
        $solicitud = $this->solicitud($doc);
        $this->prestar($solicitud)->assertForbidden();
        $solicitud = $this->accion($solicitud, 'aprobar', ['vigente_hasta' => $this->fechaOperacion(7)])->assertOk()->json('data');
        $prestamo = $this->prestar($solicitud)->assertCreated()->json('data');
        $this->prestar($solicitud)->assertConflict();
        $this->getJson('/api/v1/custodia/movimientos?pendientes=1')->assertOk()->assertJsonPath('total', 1);
        $this->travelTo(now()->addDays(8));
        $this->getJson('/api/v1/custodia/movimientos?atrasados=1')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/custodia/solicitudes/'.$solicitud['id'].'/documentos/'.$doc->id.'/archivo')->assertForbidden();
        $this->postJson('/api/v1/custodia/movimientos/'.$prestamo['id'].'/devolver', ['version' => 1, 'fecha_retorno' => $this->fechaOperacion(), 'motivo' => 'Recibido íntegro'])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/custodia/movimientos?pendientes=1')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/v1/custodia/movimientos/'.$prestamo['id'].'/devolver', ['version' => 1, 'fecha_retorno' => $this->fechaOperacion(), 'motivo' => 'Repetido'])->assertConflict();
        $this->assertDatabaseHas('movimientos_custodia_legajo', ['id' => $prestamo['id']]);
    }

    public function test_rejects_unknown_scope_missing_confidentiality_and_stale_decisions(): void
    {
        $doc = $this->documentoOperativo($this->legajoOperativo());
        $solicitud = $this->solicitud($doc);
        $this->accion($solicitud, 'aprobar')->assertUnprocessable();
        $this->accion($solicitud, 'aprobar', ['vigente_hasta' => $this->fechaOperacion(-1)])->assertUnprocessable();
        $this->accion($solicitud, 'rechazar')->assertOk();
        $this->accion($solicitud, 'aprobar', ['vigente_hasta' => $this->fechaOperacion(7)])->assertConflict();
        $this->postJson('/api/v1/custodia/solicitudes', ['legajo_id' => $doc->legajo_id, 'documentos_autorizados' => [$doc->id], 'compromiso_confidencialidad' => false])->assertUnprocessable()->assertJsonValidationErrors('compromiso_confidencialidad');
        $this->deleteJson('/api/v1/solicitudes-acceso-legajo/'.$solicitud['id'])->assertMethodNotAllowed();
        $evento = AuditoriaEvento::query()->where('entidad_id', $solicitud['id'])->firstOrFail();
        $this->deleteJson('/api/v1/auditoria-eventos/'.$evento->id)->assertConflict();
    }

    public function test_assistant_has_no_access_to_custody_and_cannot_authorize_itself(): void
    {
        $doc = $this->documentoOperativo($this->legajoOperativo());
        $solicitud = $this->solicitud($doc);
        $this->actuarComo(null, 'asistente_legajo');
        foreach (['/custodia/solicitudes', '/custodia/movimientos', '/custodia/solicitudes/'.$solicitud['id'], '/custodia/solicitudes/'.$solicitud['id'].'/alcance'] as $url) {
            $this->getJson('/api/v1'.$url)->assertForbidden();
        }
        $this->accion($solicitud, 'aprobar', ['vigente_hasta' => $this->fechaOperacion(7)])->assertForbidden();
        $this->postJson('/api/v1/custodia/solicitudes', [])->assertForbidden();
    }

    public function test_third_party_delivery_is_blocked_if_document_becomes_sensitive_after_approval(): void
    {
        $doc = $this->documentoOperativo($this->legajoOperativo());
        $solicitud = $this->solicitud($doc);
        $this->accion($solicitud, 'aprobar', ['vigente_hasta' => $this->fechaOperacion(7)])->assertOk();
        $doc->tipoDocumento->update(['es_dato_salud' => true]);
        $this->getJson('/api/v1/custodia/solicitudes/'.$solicitud['id'].'/documentos/'.$doc->id.'/archivo')->assertForbidden();
        $this->assertDatabaseCount('bitacora_accesos_legajo', 0);
    }
}
