<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\LicenciaPermiso;
use App\Models\ParametroSistema;
use App\Models\ProgresionCarrera;
use App\Models\RatificacionDocente;
use App\Models\Sancion;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\TiempoServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TiempoServicioTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlapping_periods_count_once_and_discounts_only_inside_worked_days(): void
    {
        // Días 1-10 y 5-20 se unen (20 días); 30-31 aparte (2). Descuento 18-35: dentro caen 18-20 y 30-31 (5 días).
        $this->assertSame(['dias' => 22, 'descontados' => 0], TiempoServicio::computar([[1, 10], [5, 20], [30, 31]], []));
        $this->assertSame(['dias' => 17, 'descontados' => 5], TiempoServicio::computar([[1, 10], [5, 20], [30, 31]], [[18, 35]]));
        // Contiguos se unen; descuentos superpuestos no se restan dos veces.
        $this->assertSame(['dias' => 15, 'descontados' => 5], TiempoServicio::computar([[1, 10], [11, 20]], [[3, 5], [4, 7]]));
        $this->assertSame('1 a 2 m 3 d', TiempoServicio::duracion(360 + 60 + 3, ['dias_por_mes' => 30, 'dias_por_anio' => 360])['texto']);
    }

    public function test_report_sums_all_links_at_the_cutoff_date_and_sorts_by_longest_service(): void
    {
        $this->actuarComo();
        $antigua = $this->persona('70000011');
        // Un vínculo cesado y otro vigente que se superpone un mes: el mes común se cuenta una vez.
        VinculoLaboral::factory()->create(['usuario_id' => $antigua->id, 'regimen_laboral' => 'plazo_determinado', 'fecha_inicio' => '2020-01-01', 'fecha_fin_prevista' => null, 'fecha_cese' => '2020-12-31']);
        VinculoLaboral::factory()->create(['usuario_id' => $antigua->id, 'regimen_laboral' => 'carrera', 'fecha_inicio' => '2020-12-01', 'fecha_fin_prevista' => null, 'fecha_cese' => null]);
        // Anulado: no cuenta.
        VinculoLaboral::factory()->create(['usuario_id' => $antigua->id, 'fecha_inicio' => '2000-01-01', 'fecha_fin_prevista' => null, 'anulado' => true]);
        $reciente = $this->persona('70000012');
        VinculoLaboral::factory()->create(['usuario_id' => $reciente->id, 'fecha_inicio' => '2024-01-01', 'fecha_fin_prevista' => '2024-06-30', 'fecha_cese' => null]);
        // Vínculo que empieza después del corte: la persona no aparece.
        VinculoLaboral::factory()->create(['usuario_id' => $this->persona('70000013')->id, 'fecha_inicio' => '2026-01-01', 'fecha_fin_prevista' => null]);

        $datos = $this->getJson('/api/v1/reportes/tiempo_servicio_personal?exportar=1&corte=2025-12-31')->assertOk()->assertJsonCount(2, 'data')->json();
        $this->assertSame(['70000011', '70000012'], array_column($datos['data'], 'documento'));
        [$primera, $segunda] = $datos['data'];
        // 2020-01-01 a 2025-12-31: 2192 días calendario (con dos bisiestos), 6 a 1 m 2 d con 360/30.
        $this->assertSame(2192, $primera['dias_totales']);
        $this->assertSame('2020-01-01', $primera['fecha']);
        $this->assertSame('vigente', $primera['estado_codigo']);
        $this->assertStringContainsString('Carrera:', $primera['por_tipo']);
        $this->assertSame('No aplicados', $primera['descuentos']);
        // Plazo vencido sin cese: por defecto cuenta hasta el fin previsto (182 días) y se avisa.
        $this->assertSame(182, $segunda['dias_totales']);
        $this->assertSame('sin_vigente', $segunda['estado_codigo']);
        $this->assertSame('Un vínculo con plazo vencido sin cese registrado', $segunda['observacion']);
        $this->getJson('/api/v1/reportes/tiempo_servicio_personal?estado=vigente&corte=2025-12-31')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/reportes/tiempo_servicio_personal?corte=2025-02-30')->assertUnprocessable()->assertJsonValidationErrors('corte');

        ParametroSistema::query()->create(['clave' => 'ts_vencido_hasta_corte', 'valor' => '1', 'tipo_dato' => 'int']);
        $this->getJson('/api/v1/reportes/tiempo_servicio_personal?exportar=1&corte=2025-12-31&q=70000012')->assertOk()->assertJsonPath('data.0.dias_totales', 731);
    }

    public function test_discounts_apply_only_when_the_institution_enables_them(): void
    {
        $this->actuarComo();
        $persona = $this->persona('70000021');
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2025-01-01', 'fecha_fin_prevista' => null, 'fecha_cese' => null]);
        LicenciaPermiso::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'otra', 'con_goce' => false, 'fecha_inicio' => '2025-03-01', 'fecha_fin' => '2025-03-10', 'dias' => 10]);
        LicenciaPermiso::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'otra', 'con_goce' => true, 'fecha_inicio' => '2025-04-01', 'fecha_fin' => '2025-04-10', 'dias' => 10]);
        LicenciaPermiso::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'otra', 'con_goce' => false, 'fecha_inicio' => '2025-05-01', 'fecha_fin' => '2025-05-10', 'dias' => 10, 'anulado' => true]);
        Sancion::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'SUSPENSION', 'fecha_inicio' => '2025-03-05', 'fecha_fin' => '2025-03-14', 'dias_suspension' => 10]);
        $url = '/api/v1/reportes/tiempo_servicio_personal?exportar=1&corte=2025-12-31';

        $this->getJson($url)->assertOk()->assertJsonPath('data.0.dias_totales', 365)->assertJsonPath('data.0.descuentos', 'No aplicados');
        ParametroSistema::query()->create(['clave' => 'ts_restar_licencias_sin_goce', 'valor' => '1', 'tipo_dato' => 'int']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.dias_totales', 355)->assertJsonPath('data.0.descuentos', '10 d');
        // La suspensión se superpone 6 días con la licencia: entre ambas descuentan 14, no 20.
        ParametroSistema::query()->create(['clave' => 'ts_restar_suspensiones', 'valor' => '1', 'tipo_dato' => 'int']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.dias_totales', 351)->assertJsonPath('data.0.descuentos', '14 d');
    }

    public function test_time_in_current_category_does_not_restart_with_a_ratification(): void
    {
        $this->actuarComo();
        $docente = $this->persona('70000031');
        VinculoLaboral::factory()->create(['usuario_id' => $docente->id, 'tipo_personal' => 'docente', 'fecha_inicio' => '2015-01-01', 'fecha_fin_prevista' => null]);
        $base = ['usuario_id' => $docente->id, 'anulado' => false];
        RatificacionDocente::factory()->create([...$base, 'tipo' => 'nombramiento', 'categoria_nueva' => 'auxiliar', 'resultado' => 'pendiente', 'fecha_inicio_periodo' => '2015-01-01']);
        RatificacionDocente::factory()->create([...$base, 'tipo' => 'promocion', 'categoria_anterior' => 'auxiliar', 'categoria_nueva' => 'asociado', 'resultado' => 'promovido', 'fecha_inicio_periodo' => '2020-01-01']);
        RatificacionDocente::factory()->create([...$base, 'tipo' => 'ratificacion', 'categoria_anterior' => 'asociado', 'categoria_nueva' => 'asociado', 'resultado' => 'ratificado', 'fecha_inicio_periodo' => '2025-01-01']);
        // Pendiente: no fija categoría todavía.
        RatificacionDocente::factory()->create([...$base, 'tipo' => 'promocion', 'categoria_anterior' => 'asociado', 'categoria_nueva' => 'principal', 'resultado' => 'pendiente', 'fecha_inicio_periodo' => '2025-06-01']);
        $administrativo = $this->persona('70000032');
        VinculoLaboral::factory()->create(['usuario_id' => $administrativo->id, 'fecha_inicio' => '2018-01-01', 'fecha_fin_prevista' => null]);
        ProgresionCarrera::factory()->create(['usuario_id' => $administrativo->id, 'nivel_nuevo' => 'SP-ES', 'fecha_efectiva' => '2024-12-07', 'anulado' => false]);

        $filas = collect($this->getJson('/api/v1/reportes/tiempo_servicio_personal?exportar=1&corte=2025-12-31')->assertOk()->json('data'))->keyBy('documento');
        $this->assertSame('Docente Asociado', $filas['70000031']['categoria']);
        $this->assertStringStartsWith('6 a', $filas['70000031']['tiempo_categoria']);
        $this->assertSame('Nivel SP-ES', $filas['70000032']['categoria']);
        $this->assertSame('1 a 1 m', $filas['70000032']['tiempo_categoria']);
    }

    public function test_report_requires_permission_and_the_links_feature(): void
    {
        config(['permisos.roles.sin_legajos' => ['personal.ver']]);
        $this->actuarComo(codigo: 'sin_legajos');
        $this->getJson('/api/v1/reportes/tiempo_servicio_personal')->assertForbidden();
    }

    private function persona(string $documento): Usuario
    {
        $persona = Usuario::factory()->create(['numero_doc_identidad' => $documento]);
        Legajo::factory()->create(['usuario_id' => $persona->id]);

        return $persona;
    }
}
