<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\RequerimientoDocumento;
use App\Models\TipoDocumento;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use Carbon\Carbon;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VencimientosPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-17 23:30:00', 'America/Lima'));
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $this->actuarComo();
    }

    public function test_groups_document_contract_and_overdue_requirement_alerts_by_person(): void
    {
        $legajo = $this->legajo();
        $this->documento($legajo, -1);
        $this->documento($legajo, 6);
        $this->contrato($legajo, 7);
        $this->requerimiento($legajo, -2);
        // Un documento vencido manda sobre el requerimiento fuera de plazo; cada motivo lleva su propio estado.
        $this->getJson('/api/v1/vencimientos')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('resumen.personas', 1)->assertJsonPath('resumen.vencidos', 1)->assertJsonPath('resumen.fuera_de_plazo', 0)
            ->assertJsonPath('resumen.por_vencer', 0)->assertJsonPath('data.0.total_alertas', 4)
            ->assertJsonPath('data.0.estado', 'vencido')->assertJsonPath('data.0.vencidos', 1)->assertJsonPath('data.0.fuera_de_plazo', 1)
            ->assertJsonPath('data.0.por_vencer', 2)->assertJsonPath('data.0.motivos.0.origen', 'requerimiento')
            ->assertJsonPath('data.0.motivos.0.estado', 'fuera_de_plazo')->assertJsonPath('data.0.motivos.1.estado', 'vencido');
    }

    public function test_includes_today_and_day_thirty_uses_lima_and_red_below_seven(): void
    {
        $legajo = $this->legajo();
        foreach ([0, 6, 7, 30, 31] as $dias) {
            $this->documento($legajo, $dias);
        }
        $r = $this->getJson('/api/v1/vencimientos')->assertOk()->assertJsonPath('hoy', '2026-10-17')->assertJsonPath('dias', 30)
            ->assertJsonPath('data.0.total_alertas', 4)->assertJsonPath('data.0.estado', 'por_vencer');
        $this->assertSame([0, 6, 7, 30], array_column($r->json('data.0.motivos'), 'dias_restantes'));
        $this->assertSame(['rojo', 'rojo', 'amarillo', 'amarillo'], array_column($r->json('data.0.motivos'), 'criticidad'));
        $this->getJson('/api/v1/vencimientos?dias=6')->assertOk()->assertJsonPath('data.0.total_alertas', 2);
    }

    public function test_contracts_use_latest_valid_adenda_include_expired_without_termination_and_exclude_ended_or_future(): void
    {
        $legajo = $this->legajo();
        $vinculo = $this->contrato($legajo, 2);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-10-25', 'anulado' => false]);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-12-31', 'anulado' => true]);
        $this->contrato($legajo, 3)->update(['anulado' => true]);
        $this->contrato($legajo, 4)->update(['fecha_cese' => '2026-10-16']);
        $this->contrato($legajo, 5)->update(['fecha_inicio' => '2026-10-18']);
        // Plazo vencido sin cese registrado: es una alerta vencida (antes no aparecía).
        $this->contrato($legajo, -40);
        $this->contrato($legajo, 6)->update(['fecha_fin_prevista' => null]);
        $this->getJson('/api/v1/vencimientos?origen=contrato')->assertOk()->assertJsonPath('data.0.total_alertas', 2)
            ->assertJsonPath('data.0.motivos.0.fecha', '2026-09-07')->assertJsonPath('data.0.motivos.0.estado', 'vencido')
            ->assertJsonPath('data.0.motivos.1.fecha', '2026-10-25')->assertJsonPath('data.0.motivos.1.estado', 'por_vencer')
            ->assertJsonPath('data.0.estado', 'vencido');
    }

    public function test_requirements_show_upcoming_and_overdue_with_real_states_and_effective_deadline(): void
    {
        $legajo = $this->legajo();
        $this->requerimiento($legajo, -10)->update(['prorroga_hasta' => '2026-10-20']);
        // Cerrados o esperando a la ORH (en revisión): no son alertas de la persona.
        foreach (['aceptado', 'cancelado', 'en_revision'] as $estado) {
            $this->requerimiento($legajo, -9)->update(['estado' => $estado]);
        }
        $this->requerimiento($legajo, -8)->update(['cerrado_en' => now()]);
        $this->requerimiento($legajo, 0);
        $this->requerimiento($legajo, -7)->update(['prorroga_hasta' => '2026-10-16', 'estado' => 'observado']);
        $this->requerimiento($legajo, 31);
        $this->getJson('/api/v1/vencimientos?origen=requerimiento')->assertOk()->assertJsonPath('data.0.total_alertas', 3)
            ->assertJsonPath('data.0.motivos.0.fecha', '2026-10-16')->assertJsonPath('data.0.motivos.0.estado', 'fuera_de_plazo')
            ->assertJsonPath('data.0.motivos.1.estado', 'por_vencer')->assertJsonPath('data.0.motivos.2.fecha', '2026-10-20')
            ->assertJsonPath('data.0.estado', 'fuera_de_plazo')->assertJsonPath('resumen.fuera_de_plazo', 1);
    }

    public function test_ignores_closed_legajos_invalid_documents_and_superseded_versions(): void
    {
        $cerrado = $this->legajo();
        $this->documento($cerrado, -1);
        $cerrado->update(['estado_legajo' => 'pasivo']);
        $activo = $this->legajo();
        $antiguo = $this->documento($activo, -1);
        $this->documento($activo, 365)->update(['subsana_a_documento_id' => $antiguo->id]);
        foreach (['anulado', 'falso', 'pendiente'] as $estado) {
            $this->documento($activo, -1)->update(['estado' => $estado]);
        }
        $this->documento($activo, 1)->update(['archivo_id' => null]);
        $this->documento($activo, 1)->update(['fecha_caducidad' => null]);
        $this->getJson('/api/v1/vencimientos')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_permissions_filter_sensitive_documents_and_requirements(): void
    {
        $legajo = $this->legajo();
        $this->documento($legajo, -1, 10);
        $this->documento($legajo, 3, 3);
        $this->requerimiento($legajo, -2);
        // Sin datos sensibles ni requerimientos.gestionar: solo el documento de la sección 03.
        config(['permisos.roles.prueba_solo_legajo' => ['legajo.ver']]);
        $this->actuarComo(null, 'prueba_solo_legajo');
        $origenes = $this->getJson('/api/v1/vencimientos')->assertOk()->assertJsonPath('data.0.total_alertas', 1)
            ->assertJsonPath('data.0.estado', 'por_vencer')->assertJsonPath('resumen.vencidos', 0)->json('origenes.*.id');
        $this->assertNotContains('requerimiento', $origenes);
        $this->getJson('/api/v1/vencimientos?origen=requerimiento')->assertOk()->assertJsonPath('total', 0);
        // El asistente gestiona requerimientos (requerimientos.gestionar): los ve, pero no el documento sensible.
        $this->actuarComo(null, 'asistente_legajo');
        $this->getJson('/api/v1/vencimientos')->assertOk()->assertJsonPath('data.0.total_alertas', 2)->assertJsonPath('data.0.estado', 'fuera_de_plazo');
        Sanctum::actingAs(Usuario::factory()->create());
        $this->getJson('/api/v1/vencimientos')->assertForbidden();
    }

    public function test_search_area_type_and_status_filters_match_summary_and_rows(): void
    {
        $area = UnidadOrganica::factory()->create();
        $persona = Usuario::factory()->create(['nombres' => 'Rosa', 'apellido_paterno' => 'Prueba', 'tipo_personal' => 'docente', 'unidad_organica_id' => $area->id]);
        $legajo = $this->legajo($persona);
        $this->documento($legajo, -1);
        $this->documento($this->legajo(), 1);
        $this->getJson('/api/v1/vencimientos?q=rosa&unidad_id='.$area->id.'&tipo_personal=docente&estado=vencido')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.usuario_id', $persona->id)->assertJsonPath('data.0.area', $area->nombre);
        $this->getJson('/api/v1/vencimientos?estado=por_vencer')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('resumen.vencidos', 0);
        $this->getJson('/api/v1/vencimientos?estado=fuera_de_plazo')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_paginates_people_without_duplicates_and_limits_detail_to_five_motives(): void
    {
        foreach (range(0, 20) as $dias) {
            $this->documento($this->legajo(), $dias);
        }
        $legajo = $this->legajo();
        foreach (range(-6, -1) as $dias) {
            $this->documento($legajo, $dias);
        }
        $this->getJson('/api/v1/vencimientos')->assertOk()->assertJsonPath('total', 22)->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.usuario_id', $legajo->usuario_id)->assertJsonPath('data.0.total_alertas', 6)->assertJsonCount(5, 'data.0.motivos');
        $this->getJson('/api/v1/vencimientos?page=2')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_rejects_invalid_filters(): void
    {
        foreach (['dias=0', 'dias=366', 'dias=abc', 'estado=otro', 'estado=desactualizado', 'origen=otro', 'unidad_id=invalid', 'page=0'] as $filtro) {
            $this->getJson('/api/v1/vencimientos?'.$filtro)->assertUnprocessable();
        }
    }

    private function legajo(?Usuario $persona = null): Legajo
    {
        return Legajo::factory()->create(['usuario_id' => $persona?->id ?? Usuario::factory()->create()->id, 'estado_legajo' => 'activo']);
    }

    private function documento(Legajo $legajo, int $dias, int $seccion = 3): DocumentoUsuario
    {
        $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => $seccion, 'es_dato_sensible' => false, 'es_dato_salud' => false]);

        return DocumentoUsuario::factory()->create(['legajo_id' => $legajo->id, 'usuario_id' => $legajo->usuario_id,
            'tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => $seccion, 'archivo_id' => ArchivoDocumento::factory(),
            'fecha_caducidad' => now('America/Lima')->addDays($dias)->toDateString(), 'estado' => 'cargado']);
    }

    private function contrato(Legajo $legajo, int $dias): VinculoLaboral
    {
        return VinculoLaboral::factory()->create(['usuario_id' => $legajo->usuario_id, 'fecha_inicio' => '2026-01-01',
            'fecha_fin_prevista' => now('America/Lima')->addDays($dias)->toDateString(), 'anulado' => false]);
    }

    private function requerimiento(Legajo $legajo, int $dias): RequerimientoDocumento
    {
        return RequerimientoDocumento::factory()->create(['usuario_id' => $legajo->usuario_id, 'estado' => 'abierto',
            'fecha_limite' => now('America/Lima')->addDays($dias)->toDateString()]);
    }
}
