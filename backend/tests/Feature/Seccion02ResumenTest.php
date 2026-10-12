<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Induccion;
use App\Models\Legajo;
use App\Models\ParametroSistema;
use App\Models\PeriodoPrueba;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use Database\Seeders\Seccion02CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Seccion02ResumenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-03-01 10:00:00');
        $this->seed(Seccion02CatalogoSeeder::class);
    }

    private function seccion(Usuario $persona): array
    {
        return $this->getJson('/api/v1/expedientes/'.$persona->id.'/seccion-02')->assertOk()->json('data');
    }

    private function subseccion(array $data, string $codigo): array
    {
        return collect($data['subsecciones'])->firstWhere('codigo', $codigo);
    }

    public function test_link_validity_uses_the_latest_addendum_and_configurable_notice(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $porVencer = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2025-01-01', 'fecha_fin_prevista' => '2025-12-31']);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $porVencer->id, 'numero' => 'AD-2', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-03-20']);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $porVencer->id, 'numero' => 'AD-1', 'fecha_inicio' => '2025-07-01', 'fecha_fin' => '2025-12-31']);
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2023-01-01', 'fecha_fin_prevista' => '2023-12-31']);
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2020-01-01', 'fecha_cese' => '2022-06-30']);

        $data = $this->seccion($persona);
        $this->assertSame(30, $data['dias_aviso_vencimiento']);
        $vigente = $data['vinculos'][0];
        $this->assertSame($porVencer->id, $vigente['id']);
        $this->assertTrue($vigente['vigente']);
        $this->assertSame(['estado' => 'por_vencer', 'fin_efectivo' => '2026-03-20', 'por_adenda' => true, 'dias' => 19], $vigente['vencimiento']);
        $this->assertSame(['AD-1', 'AD-2'], array_column(array_column($vigente['adendas'], 'values'), 'numero'));
        $this->assertSame(['vencido', 'cesado'], [$data['vinculos'][1]['vencimiento']['estado'], $data['vinculos'][2]['vencimiento']['estado']]);
        $this->assertFalse($data['vinculos'][1]['vigente']);

        // Los días de aviso se configuran en parametros_sistema, sin tocar código.
        ParametroSistema::factory()->create(['clave' => 'dias_aviso_vencimiento_vinculo', 'valor' => '10', 'tipo_dato' => 'int']);
        $this->assertSame('vigente', $this->seccion($persona)['vinculos'][0]['vencimiento']['estado']);
    }

    public function test_summary_counts_pending_and_required_types_and_subsection_states(): void
    {
        $this->actuarComo();
        $sinPrueba = Usuario::factory()->create(['tiene_periodo_prueba' => false]);
        $data = $this->seccion($sinPrueba);
        // 2.4 no aplica: no cuenta como pendiente ni se exige.
        $this->assertSame(['tipos' => 12, 'pendientes' => 12, 'exigidos_cargados' => 0, 'exigidos_recepcionados' => 0, 'exigidos_verificados' => 0, 'por_determinar' => 0],
            array_diff_key($data['resumen'], ['exigidos' => true]));
        $this->assertSame(['a', 'b', 'c', 'e', 'f', 'h', 'i', 'j', 'k'], array_column($data['resumen']['exigidos'], 'literal'));
        $this->assertSame(['sin_datos', 'sin_datos', 'sin_datos', 'no_aplica'], array_column($data['subsecciones'], 'estado'));
        $this->assertFalse($this->subseccion($data, '2.4')['aplica']);

        $persona = Usuario::factory()->create(['tiene_periodo_prueba' => true]);
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id]);
        // Con vínculo vigente, «Tiene período de prueba» sale del vínculo.
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2025-01-01', 'proceso_seleccion' => 'Concurso 1', 'fecha_resultado_seleccion' => '2024-12-20', 'tiene_periodo_prueba' => true]);
        $documento = fn (string $codigo, string $estado) => DocumentoUsuario::factory()->create([
            'usuario_id' => $persona->id, 'legajo_id' => $legajo->id, 'seccion_legajo_id' => 2, 'estado' => $estado,
            'tipo_documento_id' => TipoDocumento::query()->where('codigo', $codigo)->where('activo', true)->value('id'),
            'emisor' => 'ORH', 'folio_inicio' => 1, 'folio_fin' => 2,
        ]);
        $documento('S02_A_RESULTADO_SELECCION', 'verificado');
        $documento('S02_B_INFORME_REQUISITOS_PUESTO', 'cargado');
        $documento('S02_I_CARGO_PERFIL_PUESTO', 'falso');
        Induccion::factory()->create(['usuario_id' => $persona->id, 'vinculo_laboral_id' => $vinculo->id, 'tipo' => 'general']);
        Induccion::factory()->create(['usuario_id' => $persona->id, 'vinculo_laboral_id' => $vinculo->id, 'tipo' => 'especifica', 'anulado' => true, 'motivo_anulacion' => 'Error']);

        $data = $this->seccion($persona);
        $this->assertSame(13, $data['resumen']['tipos']);
        $this->assertSame(11, $data['resumen']['pendientes']);
        $this->assertSame(10, count($data['resumen']['exigidos']));
        $this->assertSame([2, 1], [$data['resumen']['exigidos_cargados'], $data['resumen']['exigidos_verificados']]);
        $seleccion = $this->subseccion($data, '2.1');
        $this->assertSame(['completa', 2, 2, 1], [$seleccion['estado'], $seleccion['exigidos'], $seleccion['exigidos_cargados'], $seleccion['exigidos_verificados']]);
        $this->assertSame('ORH', $seleccion['tipos'][0]['documento']['emisor']);
        $this->assertNull($seleccion['tipos'][0]['documento']['titular_nombre']);
        $this->assertSame('parcial', $this->subseccion($data, '2.2')['estado']);
        // La específica está anulada: no cuenta.
        $this->assertSame(['especifica'], $data['vinculos'][0]['inducciones_faltantes']);
        $this->assertSame('parcial', $this->subseccion($data, '2.3')['estado']);
        $this->assertSame('sin_datos', $this->subseccion($data, '2.4')['estado']);
    }

    public function test_trial_period_shows_remaining_days_and_overdue_alert(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['tiene_periodo_prueba' => true]);
        PeriodoPrueba::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2025-10-01', 'fecha_fin' => '2026-02-20']);
        PeriodoPrueba::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2026-02-01', 'fecha_fin' => '2026-03-11']);
        PeriodoPrueba::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2025-04-01', 'resultado' => 'aprobado', 'fecha_resultado' => '2025-04-02']);

        $periodos = $this->seccion($persona)['periodos_prueba'];
        $this->assertSame([[10, null], [-9, 'pendiente_vencido'], [null, null]], array_map(fn (array $p): array => [$p['dias_restantes'], $p['alerta']], $periodos));
    }

    public function test_annulled_records_are_a_separate_view_for_administration_only(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        Induccion::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'general', 'anulado' => true, 'motivo_anulacion' => 'Duplicada']);
        Induccion::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'especifica']);
        PeriodoPrueba::factory()->create(['usuario_id' => $persona->id, 'anulado' => true, 'motivo_anulacion' => 'Fechas mal']);
        $url = '/api/v1/expedientes/'.$persona->id.'/seccion-02/anulados';

        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.inducciones')->assertJsonPath('data.inducciones.0.values.motivo_anulacion', 'Duplicada')
            ->assertJsonCount(1, 'data.periodos_prueba');
        $this->assertCount(1, $this->seccion($persona)['inducciones']);

        $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
        $this->getJson($url)->assertForbidden();
    }
}
