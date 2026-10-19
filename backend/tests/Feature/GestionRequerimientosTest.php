<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\RequerimientoDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GestionRequerimientosTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $this->actor = $this->actuarComo();
    }

    private function crear(): array
    {
        return $this->postJson('/api/v1/requerimientos', [
            'usuario_id' => Usuario::factory()->create()->id, 'origen' => 'legajo',
            'descripcion' => 'Presentar constancia', 'fecha_requerimiento' => '2026-10-17', 'fecha_limite' => '2026-10-20',
        ])->assertCreated()->assertJsonPath('data.estado', 'abierto')->assertJsonPath('data.solicitado_por', $this->actor->id)
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.plazo_dias_habiles', null)->json('data');
    }

    private function documento(array $registro): DocumentoUsuario
    {
        $legajo = Legajo::factory()->create(['usuario_id' => $registro['usuario_id'], 'estado_legajo' => 'activo']);

        return DocumentoUsuario::factory()->create(['usuario_id' => $registro['usuario_id'], 'legajo_id' => $legajo->id,
            'seccion_legajo_id' => 3, 'tipo_documento_id' => TipoDocumento::factory()->create(['seccion_legajo_id' => 3])->id,
            'archivo_id' => ArchivoDocumento::factory(), 'estado' => 'cargado']);
    }

    private function accion(array $registro, string $accion, array $datos = []): TestResponse
    {
        return $this->postJson('/api/v1/requerimientos/'.$registro['id'].'/acciones', ['version' => $registro['version'], 'accion' => $accion, ...$datos]);
    }

    public function test_full_workflow_keeps_observation_resubmission_and_an_immutable_audit_chain(): void
    {
        $registro = $this->crear();
        $documento = $this->documento($registro);
        $registro = $this->accion($registro, 'responder', ['documento_id' => $documento->id])->assertOk()->assertJsonPath('data.estado', 'en_revision')->json('data');
        $registro = $this->accion($registro, 'observar', ['motivo' => 'Falta acreditar el emisor'])->assertOk()->assertJsonPath('data.estado', 'observado')->json('data');
        $registro = $this->accion($registro, 'responder', ['documento_id' => $documento->id])->assertOk()->json('data');
        $registro = $this->accion($registro, 'cumplir')->assertOk()->assertJsonPath('data.estado', 'cumplido')->assertJsonPath('data.version', 5)->json('data');
        $this->assertNotNull($registro['cerrado_en']);
        $this->accion($registro, 'prorrogar', ['prorroga_hasta' => '2026-11-01', 'motivo' => 'No procede'])->assertConflict();
        $historial = $this->getJson('/api/v1/requerimientos/'.$registro['id'].'/historial')->assertOk()->assertJsonPath('total', 5);
        $this->assertSame($this->actor->id, $historial->json('data.0.actor.id'));
        $eventos = AuditoriaEvento::query()->where('entidad_id', $registro['id'])->orderBy('id')->get();
        $this->assertNull($eventos[0]->hash_previo);
        $this->assertSame($eventos[0]->hash_registro, $eventos[1]->hash_previo);
        $evento = $eventos[0];
        $this->deleteJson('/api/v1/auditoria-eventos/'.$evento->id)->assertConflict();
        $this->patchJson('/api/v1/auditoria-eventos/'.$evento->id, ['accion' => 'cambiar'])->assertConflict();
    }

    public function test_extension_uses_effective_deadline_and_cancel_requires_a_reason(): void
    {
        $registro = $this->crear();
        $this->accion($registro, 'prorrogar', ['prorroga_hasta' => '2026-10-20', 'motivo' => 'Prueba'])->assertUnprocessable();
        $this->accion($registro, 'prorrogar', ['prorroga_hasta' => '2026-10-25'])->assertUnprocessable();
        $registro = $this->accion($registro, 'prorrogar', ['prorroga_hasta' => '2026-10-25', 'motivo' => 'Ampliación sustentada'])->assertOk()->json('data');
        $this->accion($registro, 'prorrogar', ['prorroga_hasta' => '2026-10-24', 'motivo' => 'Retroceder'])->assertUnprocessable();
        $this->accion($registro, 'cancelar')->assertUnprocessable();
        $registro = $this->accion($registro, 'cancelar', ['motivo' => 'Solicitud duplicada'])->assertOk()->assertJsonPath('data.estado', 'cancelado')->json('data');
        $this->assertNotNull($registro['cerrado_en']);
        $this->assertDatabaseHas('requerimientos_documentos', ['id' => $registro['id']]);
    }

    public function test_stale_versions_and_invalid_transitions_do_not_change_the_record(): void
    {
        $registro = $this->crear();
        $this->accion($registro, 'cumplir')->assertConflict();
        $this->accion($registro, 'observar', ['motivo' => 'Sin respuesta'])->assertConflict();
        $this->accion($registro, 'editar', ['descripcion' => 'Corrección', 'motivo' => 'Aclaración'])->assertOk()->assertJsonPath('data.version', 2);
        $this->accion($registro, 'cancelar', ['motivo' => 'Desde otra pestaña'])->assertConflict();
        $this->assertDatabaseHas('requerimientos_documentos', ['id' => $registro['id'], 'estado' => 'abierto', 'version' => 2]);
        $this->assertSame(2, AuditoriaEvento::query()->where('entidad_id', $registro['id'])->count());
    }

    public function test_rejects_documents_from_another_person_legajo_or_type_and_invalid_states(): void
    {
        $registro = $this->crear();
        $propio = $this->documento($registro);
        $ajeno = $this->documento($this->crear());
        $this->accion($registro, 'responder', ['documento_id' => $ajeno->id])->assertNotFound();
        RequerimientoDocumento::query()->findOrFail($registro['id'])->update(['tipo_documento_id' => $ajeno->tipo_documento_id]);
        $this->accion($registro, 'responder', ['documento_id' => $propio->id])->assertNotFound();
        RequerimientoDocumento::query()->findOrFail($registro['id'])->update(['tipo_documento_id' => null]);
        foreach (['anulado', 'falso'] as $estado) {
            $propio->update(['estado' => $estado]);
            $this->accion($registro, 'responder', ['documento_id' => $propio->id])->assertNotFound();
        }
        $propio->update(['estado' => 'cargado', 'usuario_id' => $ajeno->usuario_id]);
        $this->accion($registro, 'responder', ['documento_id' => $propio->id])->assertNotFound();
        $propio->update(['usuario_id' => $registro['usuario_id']]);
        $propio->legajo->update(['estado_legajo' => 'pasivo']);
        $this->accion($registro, 'responder', ['documento_id' => $propio->id])->assertNotFound();
    }

    public function test_dedicated_workflow_works_when_generic_writes_are_disabled_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['app.api_habilitada' => true, 'app.crud_generico_escritura' => false]);
        $registro = $this->crear();
        $this->accion($registro, 'cancelar', ['motivo' => 'Prueba de producción'])->assertOk();
        $this->postJson('/api/v1/requerimientos-documentos', [])->assertForbidden();
    }

    public function test_validation_rejects_forged_author_dates_and_unknown_fields(): void
    {
        $this->postJson('/api/v1/requerimientos', ['solicitado_por' => $this->actor->id])->assertUnprocessable()->assertJsonValidationErrors('solicitado_por');
        $this->postJson('/api/v1/requerimientos', ['usuario_id' => $this->actor->id, 'descripcion' => 'Prueba', 'origen' => 'legajo', 'fecha_requerimiento' => '2026-10-17', 'fecha_limite' => '2026-10-16'])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_limite');
        $registro = $this->crear();
        $this->accion($registro, 'cancelar', ['motivo' => 'Prueba', 'estado' => 'cumplido'])->assertUnprocessable()->assertJsonValidationErrors('estado');
        $this->accion($registro, 'inventado')->assertUnprocessable()->assertJsonValidationErrors('accion');
    }

    public function test_fulfilling_a_reviewed_requirement_removes_its_overdue_alert(): void
    {
        $registro = $this->crear();
        $documento = $this->documento($registro);
        $this->travelTo(now()->setDate(2026, 10, 21));
        $this->getJson('/api/v1/vencimientos?origen=requerimiento')->assertOk()->assertJsonPath('total', 1);
        $registro = $this->accion($registro, 'responder', ['documento_id' => $documento->id])->assertOk()->json('data');
        $this->getJson('/api/v1/vencimientos?origen=requerimiento')->assertOk()->assertJsonPath('total', 1);
        $this->accion($registro, 'cumplir')->assertOk();
        $this->getJson('/api/v1/vencimientos?origen=requerimiento')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_uploaded_pdf_can_be_submitted_while_document_review_is_pending(): void
    {
        $registro = $this->crear();
        $doc = $this->documento($registro);
        $doc->update(['estado' => 'pendiente']);
        $this->accion($registro, 'responder', ['documento_id' => $doc->id])->assertOk()->assertJsonPath('data.estado', 'en_revision');
        $this->assertSame('pendiente', $doc->fresh()->estado);
    }

    public function test_permissions_apply_to_every_dedicated_route(): void
    {
        $registro = $this->crear();
        Sanctum::actingAs(Usuario::factory()->create());
        foreach (['/requerimientos', '/requerimientos/tipos', '/requerimientos/personas?q=prueba', '/requerimientos/'.$registro['id'].'/historial', '/requerimientos/'.$registro['id'].'/documentos'] as $url) {
            $this->getJson('/api/v1'.$url)->assertForbidden();
        }
        $this->postJson('/api/v1/requerimientos', [])->assertForbidden();
        $this->accion($registro, 'cancelar', ['motivo' => 'Sin permisos'])->assertForbidden();
    }
}
