<?php

namespace Tests\Feature;

use App\Models\Capacitacion;
use App\Models\Desvinculacion;
use App\Models\EvaluacionDesempeno;
use App\Models\FormacionAcademica;
use App\Models\GoceVacacional;
use App\Models\Legajo;
use App\Models\LicenciaPermiso;
use App\Models\PeriodoVacacional;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportesInstitucionalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_staffing_counts_people_once_in_the_latest_open_link_regardless_of_account_status(): void
    {
        $this->actuarComo();
        $unidad = UnidadOrganica::factory()->create();
        $persona = Usuario::factory()->create(['activo' => false]);
        Legajo::factory()->create(['usuario_id' => $persona->id]);
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => today()->subYears(2)]);
        $actual = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'tipo_personal' => 'docente', 'unidad_organica_id' => $unidad->id, 'fecha_inicio' => today()->subYear()]);
        VinculoLaboral::factory()->create(['usuario_id' => Usuario::factory()->create()->id, 'fecha_cese' => today()->subDay()]);
        $cuentaTecnica = Usuario::factory()->create(['tipo_doc_identidad' => 'sistema', 'numero_doc_identidad' => 'SISTEMA-REPORTE']);
        VinculoLaboral::factory()->create(['usuario_id' => $cuentaTecnica->id]);
        $this->getJson('/api/v1/reportes/dotacion?exportar=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.personas_total', 1)->assertJsonPath('resumen.personas', 1)
            ->assertJsonPath('resumen.por_estado.docente', 1)->assertJsonPath('data.0.unidad', $unidad->nombre)
            ->assertJsonMissingPath('data.0._personas')->assertJsonMissingPath('data.0.documento');
        $this->getJson('/api/v1/reportes/dotacion?tipo_personal=docente&unidad_id='.$unidad->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/reportes/dotacion?tipo_personal=administrativo')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/reportes/dotacion?regimen_id='.$actual->regimen_normativo_id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('resumen.personas', 1);
        $this->getJson('/api/v1/reportes/personal?q=SISTEMA-REPORTE')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_contracts_use_the_last_valid_extension_and_the_unit_of_the_link(): void
    {
        $this->actuarComo();
        $unidad = UnidadOrganica::factory()->create();
        $persona = Usuario::factory()->create(['unidad_organica_id' => UnidadOrganica::factory()->create()->id]);
        Legajo::factory()->create(['usuario_id' => $persona->id]);
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'unidad_organica_id' => $unidad->id, 'fecha_inicio' => today()->subYear(), 'fecha_fin_prevista' => today()->subDay()]);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => today()->subMonth(), 'fecha_fin' => today()->addMonth()]);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => today(), 'fecha_fin' => today()->addYear(), 'anulado' => true]);
        $this->getJson('/api/v1/reportes/contratos?unidad_id='.$unidad->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fecha_fin', today()->addMonth()->toDateString())->assertJsonPath('data.0.adendas', 1)->assertJsonPath('data.0.estado_codigo', 'abierto');
        $this->getJson('/api/v1/reportes/contratos?estado=plazo_vencido')->assertOk()->assertJsonCount(0, 'data');
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'anulado' => true]);
        $this->getJson('/api/v1/reportes/contratos')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_movements_use_employment_dates_and_do_not_duplicate_a_registered_exit(): void
    {
        $this->actuarComo();
        $unidad = UnidadOrganica::factory()->create();
        $vinculo = VinculoLaboral::factory()->create(['fecha_inicio' => '2020-01-02', 'fecha_cese' => '2025-03-10', 'unidad_organica_id' => $unidad->id]);
        Legajo::factory()->create(['usuario_id' => $vinculo->usuario_id]);
        Desvinculacion::factory()->create(['usuario_id' => $vinculo->usuario_id, 'vinculo_laboral_id' => $vinculo->id, 'fecha_cese' => '2025-03-10', 'numero_resolucion' => 'RES-2025']);
        $this->getJson('/api/v1/reportes/movimientos?desde=2025-03-01&hasta=2025-03-31')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.estado_codigo', 'cese')->assertJsonPath('data.0.numero', 'RES-2025')->assertJsonPath('data.0.unidad', $unidad->nombre);
        $this->getJson('/api/v1/reportes/movimientos?estado=ingreso')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.fecha', '2020-01-02');
    }

    public function test_education_reports_use_obtaining_dates_and_exclude_cancelled_studies(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $persona->id]);
        FormacionAcademica::factory()->create(['usuario_id' => $persona->id, 'nivel' => 'maestria', 'fecha_obtencion' => '2025-09-15']);
        FormacionAcademica::factory()->create(['usuario_id' => $persona->id, 'nivel' => 'doctorado', 'anulado' => true, 'fecha_obtencion' => '2025-09-15']);
        FormacionAcademica::factory()->create(['usuario_id' => $persona->id, 'fecha_obtencion' => null]);
        $this->getJson('/api/v1/reportes/formacion?desde=2025-01-01&hasta=2025-12-31')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nivel', 'Maestría')->assertJsonPath('data.0.estado_codigo', 'sin_archivo');
        $this->getJson('/api/v1/reportes/formacion?nivel=tecnico')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_training_sums_known_nonnegative_hours_for_all_filtered_results(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $persona->id]);
        foreach ([20, 10, null, -5] as $horas) {
            Capacitacion::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'curso', 'horas' => $horas, 'fecha_fin' => '2025-06-10']);
        }
        Capacitacion::factory()->create(['usuario_id' => $persona->id, 'horas' => 500, 'fecha_fin' => '2025-06-10', 'anulado' => true]);
        $this->getJson('/api/v1/reportes/capacitaciones?desde=2025-01-01&hasta=2025-12-31&exportar=1')->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('resumen.horas', 30)->assertJsonPath('resumen.personas', 1);
        $this->getJson('/api/v1/reportes/capacitaciones?modalidad=taller')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('resumen.horas', 0);
    }

    public function test_vacations_separate_postponements_without_inventing_a_legal_balance(): void
    {
        $this->actuarComo();
        $vinculo = VinculoLaboral::factory()->create();
        Legajo::factory()->create(['usuario_id' => $vinculo->usuario_id]);
        $periodo = PeriodoVacacional::factory()->create(['usuario_id' => $vinculo->usuario_id, 'vinculo_laboral_id' => $vinculo->id, 'periodo_anio' => 2025, 'dias_derecho' => 30]);
        foreach (['goce' => 10, 'adelanto' => 5, 'postergacion' => 7] as $tipo => $dias) {
            GoceVacacional::factory()->create(['usuario_id' => $vinculo->usuario_id, 'periodo_vacacional_id' => $periodo->id, 'tipo' => $tipo, 'dias' => $dias]);
        }
        GoceVacacional::factory()->create(['usuario_id' => $vinculo->usuario_id, 'periodo_vacacional_id' => $periodo->id, 'tipo' => 'goce', 'dias' => 20, 'anulado' => true]);
        PeriodoVacacional::factory()->create(['usuario_id' => $vinculo->usuario_id, 'vinculo_laboral_id' => $vinculo->id, 'periodo_anio' => 2025, 'anulado' => true]);
        $this->getJson('/api/v1/reportes/vacaciones?anio=2025')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.goce', 10)->assertJsonPath('data.0.adelanto', 5)->assertJsonPath('data.0.postergacion', 7)->assertJsonMissingPath('data.0.saldo');
        $this->getJson('/api/v1/reportes/vacaciones?anio=2024')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_evaluations_require_section_access_and_never_export_restricted_scores(): void
    {
        $this->actuarComo();
        EvaluacionDesempeno::factory()->create(['usuario_id' => Legajo::factory()->create()->usuario_id, 'ciclo_anio' => 2025, 'puntaje' => 95, 'calificacion' => 'SENTINELA_RESERVADA', 'plan_mejora' => true]);
        EvaluacionDesempeno::factory()->create(['ciclo_anio' => 2024]);
        $this->getJson('/api/v1/reportes/evaluaciones?anio=2025&exportar=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.estado_codigo', 'con_plan')->assertJsonMissingPath('data.0.puntaje')->assertJsonMissingPath('data.0.calificacion');
        config(['permisos.roles.lector_sin_seccion' => ['legajo.ver']]);
        $this->actuarComo(codigo: 'lector_sin_seccion');
        $this->getJson('/api/v1/reportes/evaluaciones?anio=2025')->assertForbidden();
        $this->getJson('/api/v1/reportes/opciones')->assertOk()->assertJsonMissing(['id' => 'evaluaciones']);
    }

    public function test_licenses_overlap_the_requested_period_and_hide_medical_leave_from_a_reader(): void
    {
        $this->actuarComo(codigo: 'asistente_legajo');
        $persona = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $persona->id]);
        LicenciaPermiso::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'permiso', 'fecha_inicio' => '2024-12-28', 'fecha_fin' => '2025-01-05', 'dias' => 9, 'con_goce' => false]);
        LicenciaPermiso::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'descanso_medico', 'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2025-01-05']);
        $url = '/api/v1/reportes/licencias?desde=2025-01-01&hasta=2025-01-31';
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.dias', 9)->assertJsonPath('data.0.estado_codigo', 'sin_goce');
        $this->actuarComo();
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
    }
}
