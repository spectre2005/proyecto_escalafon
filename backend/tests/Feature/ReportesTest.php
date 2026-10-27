<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\ConfiguracionFuncionalidad;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use App\Support\ConfiguracionFuncionalidades;
use App\Support\ConsultaReportes;
use App\Support\ReportesSecciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportesTest extends TestCase
{
    use RefreshDatabase;

    private function tipo(array $datos = []): TipoDocumento
    {
        return TipoDocumento::factory()->create($datos + ['seccion_legajo_id' => 3, 'obligatorio' => true,
            'activo' => true, 'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true]);
    }

    private function documento(Legajo $legajo, TipoDocumento $tipo, array $datos = []): DocumentoUsuario
    {
        return DocumentoUsuario::factory()->create($datos + ['usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id,
            'tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => $tipo->seccion_legajo_id, 'estado' => 'cargado',
            'archivo_id' => ArchivoDocumento::factory()->create()->id, 'folio_inicio' => 1, 'folio_fin' => 3]);
    }

    public function test_requires_a_session_and_each_reports_existing_permission(): void
    {
        $this->getJson('/api/v1/reportes/personal')->assertUnauthorized();
        $this->actuarComo(codigo: 'sin_permisos');
        $this->getJson('/api/v1/reportes/opciones')->assertForbidden();
        $this->getJson('/api/v1/reportes/personal?exportar=1')->assertForbidden();
        config(['permisos.roles.lector_personal' => ['personal.ver']]);
        $this->actuarComo(codigo: 'lector_personal');
        $this->getJson('/api/v1/reportes/opciones')->assertOk()->assertJsonCount(2, 'data.reportes')->assertJsonPath('data.reportes.0.id', 'dotacion');
        $this->getJson('/api/v1/reportes/pendientes')->assertForbidden();
    }

    public function test_reports_are_grouped_by_purpose_in_a_fixed_order_and_section_queries_go_last(): void
    {
        $this->actuarComo();
        $reportes = collect($this->getJson('/api/v1/reportes/opciones')->assertOk()->json('data.reportes'));
        $grupos = $reportes->pluck('grupo')->unique()->values()->all();
        $this->assertSame(['Personal y dotación', 'Contratos y plazos', 'Control documental', 'Formación y desarrollo', 'Vacaciones y licencias',
            'Consultas por sección del legajo'], $grupos);
        $this->assertSame(['dotacion', 'personal', 'movimientos', 'contratos', 'pendientes', 'formacion', 'capacitaciones', 'vacaciones', 'licencias'],
            $reportes->where('grupo', '!=', 'Consultas por sección del legajo')->pluck('id')->values()->all());
        // Las consultas por sección, por número de sección; ningún reporte se pierde al reagrupar.
        $secciones = $reportes->where('grupo', 'Consultas por sección del legajo')->pluck('seccion_origen')->values()->all();
        $ordenadas = $secciones;
        sort($ordenadas);
        $this->assertSame($ordenadas, $secciones);
        // Ninguno se pierde: solo faltan los que se exportan desde su propio panel.
        $this->assertCount(count(ConsultaReportes::catalogo()) - count(ConsultaReportes::EN_SU_PANEL), $reportes);
        // Los permisos siguen decidiendo qué se ofrece: sin datos sensibles no aparecen las consultas de las secciones 01 y 10.
        $this->actuarComo(codigo: 'asistente_legajo');
        $ids = collect($this->getJson('/api/v1/reportes/opciones')->assertOk()->json('data.reportes'))->pluck('id');
        foreach (['ficha_personal', 'familiares', 'discapacidad', 'sst_bienestar'] as $sensible) {
            $this->assertNotContains($sensible, $ids);
        }
    }

    public function test_controversies_belong_to_section_09_and_require_sensitive_data(): void
    {
        // Controversias es de la sección 09 (catálogo, s09.controversias, Anexo 1): sin datos sensibles no se ofrece ni se consulta.
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->assertNotContains('controversias', collect($this->getJson('/api/v1/reportes/opciones')->assertOk()->json('data.reportes'))->pluck('id'));
        $this->getJson('/api/v1/reportes/controversias')->assertForbidden();
        $this->getJson('/api/v1/reportes/controversias?exportar=1')->assertForbidden();
        $this->actuarComo();
        $reporte = collect($this->getJson('/api/v1/reportes/opciones')->assertOk()->json('data.reportes'))->firstWhere('id', 'controversias');
        $this->assertSame(9, $reporte['seccion_origen']);
        $this->getJson('/api/v1/reportes/controversias')->assertOk();
    }

    public function test_every_main_report_states_its_question_and_section_queries_have_their_own_description(): void
    {
        $this->actuarComo();
        $reportes = collect($this->getJson('/api/v1/reportes/opciones')->assertOk()->json('data.reportes'));
        foreach ($reportes->where('grupo', '!=', ConsultaReportes::CONSULTAS_SECCION) as $reporte) {
            $this->assertMatchesRegularExpression('/^¿.+\?$/u', (string) $reporte['pregunta'], $reporte['id']);
        }
        foreach ($reportes as $reporte) {
            $this->assertStringNotContainsString('Información registrada en la sección', $reporte['descripcion'], $reporte['id']);
            $this->assertNotSame('', trim($reporte['descripcion']), $reporte['id']);
        }
    }

    public function test_personal_information_report_offers_no_birth_date_range_and_others_keep_theirs(): void
    {
        $this->actuarComo();
        $reportes = collect($this->getJson('/api/v1/reportes/opciones')->assertOk()->json('data.reportes'))->keyBy('id');
        $this->assertNull($reportes['ficha_personal']['fecha']);
        $this->assertSame('Inicio del vínculo', $reportes['contratos']['fecha']);
        $this->assertSame('Nacimiento del familiar', $reportes['familiares']['fecha']);
    }

    public function test_personnel_uses_current_unit_and_separates_account_from_employment(): void
    {
        $this->actuarComo();
        $anterior = UnidadOrganica::factory()->create();
        $actual = UnidadOrganica::factory()->create();
        $persona = Usuario::factory()->create(['numero_doc_identidad' => '00123456', 'unidad_organica_id' => $anterior->id, 'activo' => false]);
        Legajo::factory()->create(['usuario_id' => $persona->id]);
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'unidad_organica_id' => $actual->id, 'nombre_puesto' => 'Puesto actual']);
        $this->getJson('/api/v1/reportes/personal?unidad_id='.$actual->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.documento', '00123456')->assertJsonPath('data.0.puesto', 'Puesto actual')
            ->assertJsonPath('data.0.cuenta', 'Deshabilitada')->assertJsonPath('data.0.estado_codigo', 'con_vinculo');
        $this->getJson('/api/v1/reportes/personal?unidad_id='.$anterior->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_export_contains_all_filtered_rows_instead_of_only_the_current_page(): void
    {
        $this->actuarComo();
        Usuario::factory()->has(Legajo::factory(), 'legajos')->count(23)->create(['apellido_paterno' => 'ReporteFiltro']);
        $url = '/api/v1/reportes/personal?q=ReporteFiltro';
        $this->getJson($url)->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 23)->assertJsonPath('meta.last_page', 3);
        $this->getJson($url.'&page=3')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson($url.'&exportar=1')->assertOk()->assertJsonCount(23, 'data')->assertJsonPath('meta.generado_por', fn ($valor) => is_string($valor));
        // La exportación no depende del tamaño de la tabla aunque venga en la URL.
        $this->getJson($url.'&exportar=1&por_pagina=10&page=2')->assertOk()->assertJsonCount(23, 'data')->assertJsonPath('meta.total', 23);
    }

    public function test_reports_with_their_own_panel_are_not_repeated_but_can_still_be_exported(): void
    {
        $this->actuarComo();
        $ids = collect($this->getJson('/api/v1/reportes/opciones')->assertOk()->json('data.reportes'))->pluck('id');
        $this->assertNotContains('vencimientos', $ids);
        $this->assertNotContains('evaluaciones', $ids);
        $this->getJson('/api/v1/reportes/vencimientos?exportar=1&origen=contrato')->assertOk();
        $this->getJson('/api/v1/reportes/evaluaciones?exportar=1&anio=')->assertOk()->assertJsonPath('meta.filtros.anio', null);
        $this->getJson('/api/v1/reportes/vencimientos?origen=otro')->assertUnprocessable();
        // Sin el panel de Evaluaciones, su reporte vuelve al menú de Reportes.
        ConfiguracionFuncionalidad::query()->updateOrCreate(['codigo' => 'menu.evaluaciones'], ['activa' => false, 'motivo' => 'Prueba', 'updated_by' => Usuario::query()->firstOrFail()->id]);
        $this->getJson('/api/v1/reportes/opciones')->assertOk()->assertJsonFragment(['id' => 'evaluaciones']);
        $this->getJson('/api/v1/reportes/inventario')->assertNotFound();
    }

    public function test_section_filter_hides_sensitive_sections_from_a_reader(): void
    {
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson('/api/v1/reportes/opciones')->assertOk()->assertJsonMissing(['id' => 10, 'nombre' => 'Seguridad y Salud en el Trabajo (SST) y Bienestar Social']);
    }

    public function test_pending_report_distinguishes_missing_undetermined_and_superseded_documents(): void
    {
        $this->actuarComo();
        $legajo = Legajo::factory()->create(['numero_legajo' => 'PEND-PRUEBA']);
        $falta = $this->tipo(['nombre' => 'Documento que falta']);
        $incierto = $this->tipo(['nombre' => 'Condición sin responder', 'requiere_colegiatura' => true]);
        $legajo->usuario->update(['exige_colegiatura' => null]);
        $tipo = $this->tipo(['nombre' => 'Documento corregido']);
        $anterior = $this->documento($legajo, $tipo, ['estado' => 'observado']);
        $this->documento($legajo, $tipo, ['estado' => 'aceptado', 'subsana_a_documento_id' => $anterior->id, 'fecha_recepcion' => today(), 'recepcionado_por' => $legajo->usuario_id]);
        $data = $this->getJson('/api/v1/reportes/pendientes?q=PEND-PRUEBA&exportar=1')->assertOk()->json('data');
        $filas = collect($data)->keyBy('detalle');
        $this->assertSame('faltante', $filas[$falta->nombre]['estado_codigo']);
        $this->assertSame('por_determinar', $filas[$incierto->nombre]['estado_codigo']);
        $this->assertFalse($filas->has('Documento corregido'));
    }

    public function test_disabled_optional_and_inapplicable_types_do_not_create_missing_documents(): void
    {
        $this->actuarComo();
        $legajo = Legajo::factory()->create(['numero_legajo' => 'SIN-FALTAS']);
        $legajo->usuario->update(['exige_colegiatura' => false]);
        $this->tipo(['nombre' => 'Opcional del reporte', 'obligatorio' => false]);
        $this->tipo(['nombre' => 'No aplica al reporte', 'requiere_colegiatura' => true]);
        $tipo = TipoDocumento::query()->where('codigo', 'FORM_ESTUDIOS_BASICOS')->firstOrFail();
        $data = collect($this->getJson('/api/v1/reportes/pendientes?q=SIN-FALTAS&exportar=1')->assertOk()->json('data'));
        $this->assertFalse($data->contains('detalle', 'Opcional del reporte'));
        $this->assertFalse($data->contains('detalle', 'No aplica al reporte'));
        $codigo = ConfiguracionFuncionalidades::deTipo($tipo->codigo, 3)[0];
        ConfiguracionFuncionalidad::query()->updateOrCreate(['codigo' => $codigo], ['activa' => false, 'motivo' => 'Prueba', 'updated_by' => $legajo->usuario_id]);
        ConfiguracionFuncionalidades::olvidar();
        $this->getJson('/api/v1/reportes/pendientes?q=SIN-FALTAS&exportar=1')->assertOk()->assertJsonMissing(['detalle' => $tipo->nombre]);
    }

    public function test_family_documents_are_checked_per_family_member(): void
    {
        $this->actuarComo();
        $legajo = Legajo::factory()->create(['numero_legajo' => 'FAMILIA-REPORTE']);
        $legajo->usuario->update(['tiene_derechohabientes' => true]);
        Familiar::factory()->create(['usuario_id' => $legajo->usuario_id, 'parentesco' => 'hijo', 'apellidos_nombres' => 'Hijo menor', 'fecha_nacimiento' => today()->subYears(8), 'anulado' => false]);
        Familiar::factory()->create(['usuario_id' => $legajo->usuario_id, 'parentesco' => 'hijo', 'apellidos_nombres' => 'Hijo mayor', 'fecha_nacimiento' => today()->subYears(22), 'anulado' => false]);
        $data = collect($this->getJson('/api/v1/reportes/pendientes?q=FAMILIA-REPORTE&exportar=1')->assertOk()->json('data'));
        $this->assertTrue($data->contains(fn ($fila) => str_contains($fila['detalle'], 'Hijo menor')));
        $this->assertFalse($data->contains(fn ($fila) => str_contains($fila['detalle'], 'Hijo mayor')));
    }

    public function test_expiry_report_uses_latest_extension_and_respects_sensitive_permissions(): void
    {
        $this->actuarComo(codigo: 'asistente_legajo');
        $legajo = Legajo::factory()->create(['numero_legajo' => 'VENCE-REPORTE']);
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $legajo->usuario_id, 'fecha_inicio' => today()->subMonth(), 'fecha_fin_prevista' => today()->addDays(2)]);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => today(), 'fecha_fin' => today()->addDays(60), 'anulado' => false]);
        $this->documento($legajo, $this->tipo(['seccion_legajo_id' => 10, 'es_dato_salud' => true]), ['fecha_caducidad' => today()->addDay()]);
        $this->getJson('/api/v1/reportes/vencimientos?q=VENCE-REPORTE&dias=30&exportar=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/reportes/vencimientos?q=VENCE-REPORTE&dias=90')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.origen', 'Contrato');
    }

    public function test_invalid_filters_are_rejected_and_upper_date_can_be_used_alone(): void
    {
        $this->actuarComo();
        $this->getJson('/api/v1/reportes/personal?estado=aceptado')->assertUnprocessable();
        $this->getJson('/api/v1/reportes/personal?desde=2026-10-20&hasta=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/v1/reportes/personal?hasta=2099-01-01')->assertOk();
        $this->getJson('/api/v1/reportes/inexistente')->assertNotFound();
    }

    public function test_reports_follow_the_institution_configuration(): void
    {
        $this->actuarComo();
        foreach (['s05.vacaciones', 's06.compensaciones'] as $codigo) {
            ConfiguracionFuncionalidades::cambiar($codigo, false, 'Prueba', Usuario::query()->firstOrFail());
        }
        $opciones = $this->getJson('/api/v1/reportes/opciones')->assertOk()->json('data');
        $ids = collect($opciones['reportes'])->pluck('id');
        foreach (['vacaciones', 'beneficios', 'tiempo_servicios'] as $tipo) {
            $this->assertNotContains($tipo, $ids, $tipo);
        }
        $this->assertContains('licencias', $ids);
        $this->assertNotContains(6, collect($opciones['secciones'])->pluck('id'));
        $this->getJson('/api/v1/reportes/vacaciones')->assertStatus(409)->assertJsonPath('message', '«Vacaciones (períodos y goces)» está desactivada en la configuración de la institución.');
        $this->getJson('/api/v1/reportes/beneficios?exportar=1')->assertStatus(409);
    }

    public function test_every_report_names_an_existing_feature(): void
    {
        foreach (ConsultaReportes::FUNCIONALIDADES as $tipo => $codigo) {
            $this->assertArrayHasKey($tipo, ConsultaReportes::catalogo(), $tipo);
            $this->assertArrayHasKey($codigo, ConfiguracionFuncionalidades::catalogo(), $codigo);
        }
        // Cada consulta por sección depende de una funcionalidad de su misma sección.
        foreach (ReportesSecciones::DEFINICIONES as $tipo => $definicion) {
            $this->assertSame($definicion['seccion'], ConfiguracionFuncionalidades::definicion(ConsultaReportes::FUNCIONALIDADES[$tipo])['seccion'], $tipo);
        }
    }
}
