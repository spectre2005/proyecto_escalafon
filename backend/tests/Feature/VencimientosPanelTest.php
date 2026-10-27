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
use App\Support\PuestoActual;
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
        $vencido = $this->contrato($legajo, -40);
        $this->contrato($legajo, 6)->update(['fecha_fin_prevista' => null]);
        // Cada motivo trae el id de su registro de origen: el panel enlaza directo a él.
        $this->getJson('/api/v1/vencimientos?origen=contrato')->assertOk()->assertJsonPath('data.0.total_alertas', 2)
            ->assertJsonPath('data.0.motivos.0.id', $vencido->id)->assertJsonPath('data.0.motivos.1.id', $vinculo->id)
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
        // El estado filtra las filas, no los contadores.
        $this->getJson('/api/v1/vencimientos?estado=por_vencer')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('resumen.vencidos', 1)->assertJsonPath('resumen.por_vencer', 1);
        $this->getJson('/api/v1/vencimientos?estado=fuera_de_plazo')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_counters_ignore_the_status_filter_but_follow_the_other_filters(): void
    {
        $area = UnidadOrganica::factory()->create();
        $docente = Usuario::factory()->create(['tipo_personal' => 'docente', 'unidad_organica_id' => $area->id]);
        $this->documento($this->legajo($docente), -1);
        $this->requerimiento($this->legajo(), -3);
        $this->documento($this->legajo(Usuario::factory()->create(['tipo_personal' => 'administrativo'])), 10);
        $panorama = ['personas' => 3, 'vencidos' => 1, 'fuera_de_plazo' => 1, 'por_vencer' => 1];

        // Mismos contadores con cualquier estado; las filas sí cambian.
        foreach (['' => 3, 'vencido' => 1, 'fuera_de_plazo' => 1, 'por_vencer' => 1] as $estado => $filas) {
            $this->getJson('/api/v1/vencimientos?estado='.$estado)->assertOk()->assertJsonPath('resumen', $panorama)->assertJsonPath('total', $filas);
        }
        // Los demás filtros sí los cambian.
        $this->getJson('/api/v1/vencimientos?tipo_personal=docente&estado=por_vencer')->assertOk()
            ->assertJsonPath('resumen', ['personas' => 1, 'vencidos' => 1, 'fuera_de_plazo' => 0, 'por_vencer' => 0])->assertJsonPath('total', 0);
        $this->getJson('/api/v1/vencimientos?unidad_id='.$area->id)->assertOk()->assertJsonPath('resumen.personas', 1);
        $this->getJson('/api/v1/vencimientos?origen=requerimiento')->assertOk()->assertJsonPath('resumen', ['personas' => 1, 'vencidos' => 0, 'fuera_de_plazo' => 1, 'por_vencer' => 0]);
        $this->getJson('/api/v1/vencimientos?dias=5&estado=vencido')->assertOk()->assertJsonPath('resumen', ['personas' => 2, 'vencidos' => 1, 'fuera_de_plazo' => 1, 'por_vencer' => 0]);
        $this->getJson('/api/v1/vencimientos?q='.$docente->numero_doc_identidad)->assertOk()->assertJsonPath('resumen.personas', 1);
    }

    public function test_area_follows_the_same_rule_as_the_legajo_header(): void
    {
        $vigente = UnidadOrganica::factory()->create(['nombre' => 'AREA VIGENTE']);
        $persona = Usuario::factory()->create(['unidad_organica_id' => UnidadOrganica::factory()->create(['nombre' => 'AREA DE LA PERSONA'])->id]);
        $legajo = $this->legajo($persona);
        $this->documento($legajo, 2);
        $abierto = fn (array $datos) => VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'anulado' => false, 'fecha_cese' => null, ...$datos]);
        // El vigente manda sobre otro abierto más reciente que todavía no empieza y sobre uno con el plazo vencido.
        $abierto(['unidad_organica_id' => $vigente->id, 'fecha_inicio' => '2025-01-01', 'fecha_fin_prevista' => null]);
        $abierto(['unidad_organica_id' => UnidadOrganica::factory()->create(['nombre' => 'AREA FUTURA'])->id, 'fecha_inicio' => '2026-12-01', 'fecha_fin_prevista' => null]);
        $abierto(['unidad_organica_id' => UnidadOrganica::factory()->create(['nombre' => 'AREA VENCIDA'])->id, 'fecha_inicio' => '2026-02-01', 'fecha_fin_prevista' => '2026-06-30']);
        $mismaQueLaFicha = function () use ($persona): void {
            $ficha = PuestoActual::de($persona->fresh());
            $this->getJson('/api/v1/vencimientos')->assertOk()->assertJsonPath('data.0.area', $ficha['unidad_organica']);
        };
        $mismaQueLaFicha();
        $this->getJson('/api/v1/vencimientos?unidad_id='.$vigente->id)->assertOk()->assertJsonPath('total', 1);

        // Sin vigente: el abierto más reciente (el que empieza en diciembre), como la ficha.
        VinculoLaboral::query()->where('unidad_organica_id', $vigente->id)->update(['fecha_cese' => '2026-10-01']);
        $mismaQueLaFicha();
        $this->getJson('/api/v1/vencimientos')->assertJsonPath('data.0.area', 'AREA FUTURA');

        // Sin vínculo abierto: la unidad registrada en la persona.
        VinculoLaboral::query()->where('usuario_id', $persona->id)->update(['fecha_cese' => '2026-10-01']);
        $mismaQueLaFicha();
        $this->getJson('/api/v1/vencimientos')->assertJsonPath('data.0.area', 'AREA DE LA PERSONA');
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
        // 10 por defecto (PorPagina): 22 personas en tres páginas, sin repetir ninguna.
        $primera = $this->getJson('/api/v1/vencimientos')->assertOk()->assertJsonPath('total', 22)->assertJsonCount(10, 'data')
            ->assertJsonPath('per_page', 10)->assertJsonPath('from', 1)->assertJsonPath('to', 10)->assertJsonPath('last_page', 3)
            ->assertJsonPath('data.0.usuario_id', $legajo->usuario_id)->assertJsonPath('data.0.total_alertas', 6)->assertJsonCount(5, 'data.0.motivos');
        $segunda = $this->getJson('/api/v1/vencimientos?page=2')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('from', 11);
        $ultima = $this->getJson('/api/v1/vencimientos?page=3')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('to', 22);
        $ids = [...$primera->json('data.*.usuario_id'), ...$segunda->json('data.*.usuario_id'), ...$ultima->json('data.*.usuario_id')];
        $this->assertCount(22, array_unique($ids));
        // Página inexistente: sin filas, pero con los datos reales que usa el frontend para ir a la última válida.
        $this->getJson('/api/v1/vencimientos?page=4')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('total', 22)
            ->assertJsonPath('current_page', 4)->assertJsonPath('last_page', 3)->assertJsonPath('from', null);
        $this->getJson('/api/v1/vencimientos?page=9&por_pagina=15&dias=30')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('current_page', 9)->assertJsonPath('last_page', 2)->assertJsonPath('per_page', 15);
        // Una búsqueda sin resultados es otra cosa: total 0.
        $this->getJson('/api/v1/vencimientos?page=2&q=NOEXISTE')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('last_page', 1);
    }

    public function test_page_size_options_keep_filters_in_the_page_links_and_reject_other_sizes(): void
    {
        foreach (range(0, 20) as $dias) {
            $this->documento($this->legajo(), $dias);
        }
        $this->getJson('/api/v1/vencimientos?por_pagina=15')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('last_page', 2);
        $this->getJson('/api/v1/vencimientos?por_pagina=20')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('per_page', 20);
        $this->getJson('/api/v1/vencimientos?por_pagina=10&dias=60&origen=documento')->assertOk()
            ->assertJsonPath('next_page_url', fn (string $url): bool => str_contains($url, 'por_pagina=10') && str_contains($url, 'dias=60') && str_contains($url, 'origen=documento') && str_contains($url, 'page=2'));
        foreach (['por_pagina=50', 'por_pagina=100', 'por_pagina=5', 'por_pagina=abc'] as $filtro) {
            $this->getJson('/api/v1/vencimientos?'.$filtro)->assertUnprocessable()->assertJsonValidationErrors('por_pagina');
        }
    }

    public function test_later_pages_apply_the_same_permissions(): void
    {
        foreach (range(1, 11) as $dias) {
            $this->documento($this->legajo(), $dias, 10);
        }
        $this->documento($this->legajo(), 30, 3);
        // Sin datos sensibles: las 11 alertas de la sección 10 no aparecen en ninguna página.
        config(['permisos.roles.prueba_solo_legajo' => ['legajo.ver']]);
        $this->actuarComo(null, 'prueba_solo_legajo');
        $this->getJson('/api/v1/vencimientos')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('last_page', 1);
        $this->getJson('/api/v1/vencimientos?page=2')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('total', 1);
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
