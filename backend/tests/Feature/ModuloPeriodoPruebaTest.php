<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\PeriodoPrueba;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Database\Seeders\Seccion02CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2.4 Período de prueba con «Tiene período de prueba» en No: sin datos no aplica (pantalla explicativa, entrada
 * atenuada); con períodos no anulados o documentos m se sigue viendo, sin exigirse ni contarse. Lo registrado no se pierde.
 */
class ModuloPeriodoPruebaTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $persona;

    private VinculoLaboral $vinculo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Seccion02CatalogoSeeder::class);
        $this->actuarComo();
        $this->persona = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'activo']);
        $this->vinculo = VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'tiene_periodo_prueba' => false]);
    }

    /** @return array{0: array, 1: array} sección 02 y ficha */
    private function estado(): array
    {
        $base = '/api/v1/expedientes/'.$this->persona->id;

        return [$this->getJson($base.'/seccion-02')->assertOk()->json('data'), $this->getJson($base)->assertOk()->json('data')];
    }

    private function prueba(array $seccion): array
    {
        return collect($seccion['subsecciones'])->firstWhere('codigo', '2.4');
    }

    public function test_no_without_data_does_not_apply(): void
    {
        [$seccion, $ficha] = $this->estado();
        $this->assertSame([false, false], [$seccion['tiene_periodo_prueba'], $seccion['prueba_con_datos']]);
        $this->assertSame([false, false], [$ficha['tiene_periodo_prueba'], $ficha['prueba_con_datos']]);
        $this->assertFalse($this->prueba($seccion)['aplica']);
    }

    public function test_no_with_a_registered_period_stays_visible_without_counting(): void
    {
        $periodo = PeriodoPrueba::factory()->create(['usuario_id' => $this->persona->id, 'vinculo_laboral_id' => $this->vinculo->id]);
        [$seccion, $ficha] = $this->estado();
        $this->assertTrue($seccion['prueba_con_datos']);
        $this->assertTrue($ficha['prueba_con_datos']);
        $this->assertSame([$periodo->id], array_column($seccion['periodos_prueba'], 'id'));
        // Se muestra, pero con la bandera en No no se exige ni se cuenta.
        $this->assertFalse($this->prueba($seccion)['aplica']);
        $this->assertSame('no_aplica', $this->prueba($seccion)['estado']);
    }

    public function test_no_with_a_loaded_m_document_stays_visible(): void
    {
        DocumentoUsuario::factory()->create(['usuario_id' => $this->persona->id, 'legajo_id' => $this->persona->legajos()->value('id'), 'seccion_legajo_id' => 2,
            'tipo_documento_id' => TipoDocumento::query()->where('codigo', 'S02_M_EVALUACION_PERIODO_PRUEBA')->value('id'), 'estado' => 'observado', 'folio_inicio' => 1, 'folio_fin' => 1]);
        [$seccion, $ficha] = $this->estado();
        $this->assertTrue($seccion['prueba_con_datos']);
        $this->assertTrue($ficha['prueba_con_datos']);
        $m = collect($this->prueba($seccion)['tipos'])->firstWhere('codigo', 'S02_M_EVALUACION_PERIODO_PRUEBA');
        $this->assertSame(1, $m['documentos']);
    }

    public function test_an_annulled_period_does_not_count_as_data_but_is_kept(): void
    {
        $anulado = PeriodoPrueba::factory()->create(['usuario_id' => $this->persona->id, 'vinculo_laboral_id' => $this->vinculo->id, 'anulado' => true, 'motivo_anulacion' => 'Fechas mal']);
        [$seccion] = $this->estado();
        $this->assertFalse($seccion['prueba_con_datos']);
        // Sin perder lo registrado: sigue en la base y en la vista de anulados.
        $this->assertDatabaseHas('periodos_prueba', ['id' => $anulado->id]);
        $anulados = $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/seccion-02/anulados')->assertOk()->json('data.periodos_prueba');
        $this->assertSame([$anulado->id], array_column($anulados, 'id'));
    }

    public function test_with_yes_or_unanswered_there_is_no_exception_to_apply(): void
    {
        $this->vinculo->update(['tiene_periodo_prueba' => null]);
        [$seccion] = $this->estado();
        $this->assertTrue($this->prueba($seccion)['aplica']);
        $this->vinculo->update(['tiene_periodo_prueba' => true]);
        [$seccion] = $this->estado();
        $this->assertSame([true, true], [$seccion['tiene_periodo_prueba'], $this->prueba($seccion)['aplica']]);
    }
}
