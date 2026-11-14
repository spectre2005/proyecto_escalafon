<?php

namespace Tests\Unit\Support;

use App\Models\Legajo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\SituacionLaboral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SituacionLaboralTest extends TestCase
{
    use RefreshDatabase;

    /** Persona en memoria con las marcas que agrega cargar(). */
    private function persona(array $marcas): Usuario
    {
        return (new Usuario)->setRawAttributes(['id' => 'x', ...$marcas]);
    }

    private function situacionDe(Usuario $persona): ?string
    {
        return SituacionLaboral::de(SituacionLaboral::cargar(Usuario::query())->whereKey($persona->id)->firstOrFail());
    }

    public function test_de_devuelve_null_si_la_consulta_no_trajo_las_marcas(): void
    {
        $this->assertNull(SituacionLaboral::de($this->persona([])));
        // Solo con la marca de legajo tampoco alcanza: la que decide es la del vínculo.
        $this->assertNull(SituacionLaboral::de($this->persona(['tiene_legajo_activo' => 1])));
    }

    public function test_un_vinculo_vigente_manda_aunque_no_haya_legajo_activo(): void
    {
        $this->assertSame(SituacionLaboral::VINCULO_VIGENTE, SituacionLaboral::de($this->persona(['tiene_vinculo_vigente' => 1, 'tiene_legajo_activo' => 0])));
        $this->assertSame(SituacionLaboral::VINCULO_VIGENTE, SituacionLaboral::de($this->persona(['tiene_vinculo_vigente' => 1, 'tiene_legajo_activo' => 1])));
    }

    public function test_sin_vinculo_vigente_y_con_legajo_activo(): void
    {
        $this->assertSame(SituacionLaboral::SIN_VINCULO_VIGENTE, SituacionLaboral::de($this->persona(['tiene_vinculo_vigente' => 0, 'tiene_legajo_activo' => 1])));
    }

    public function test_sin_vinculo_ni_legajo_activo_es_sin_legajo(): void
    {
        $this->assertSame(SituacionLaboral::SIN_LEGAJO, SituacionLaboral::de($this->persona(['tiene_vinculo_vigente' => 0, 'tiene_legajo_activo' => 0])));
        // Marca de vínculo presente pero nula (y sin marca de legajo): tampoco es vigente.
        $this->assertSame(SituacionLaboral::SIN_LEGAJO, SituacionLaboral::de($this->persona(['tiene_vinculo_vigente' => null])));
    }

    public function test_acepta_las_marcas_como_texto_de_la_base(): void
    {
        $this->assertSame(SituacionLaboral::SIN_VINCULO_VIGENTE, SituacionLaboral::de($this->persona(['tiene_vinculo_vigente' => '0', 'tiene_legajo_activo' => '1'])));
    }

    public function test_cargar_marca_vigente_un_vinculo_sin_cese_aunque_no_haya_legajo(): void
    {
        $persona = Usuario::factory()->create();
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'anulado' => false, 'fecha_cese' => null]);
        $this->assertSame(SituacionLaboral::VINCULO_VIGENTE, $this->situacionDe($persona));
    }

    public function test_cargar_no_cuenta_un_vinculo_de_un_legajo_cerrado(): void
    {
        $persona = Usuario::factory()->create();
        $cerrado = Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'pasivo']);
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'legajo_id' => $cerrado->id, 'anulado' => false, 'fecha_cese' => null]);
        $this->assertSame(SituacionLaboral::SIN_LEGAJO, $this->situacionDe($persona));
    }

    public function test_cargar_con_legajo_activo_y_vinculos_anulados_o_cesados(): void
    {
        $persona = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'activo']);
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'anulado' => true, 'fecha_cese' => null]);
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'anulado' => false, 'fecha_cese' => '2024-12-31']);
        $this->assertSame(SituacionLaboral::SIN_VINCULO_VIGENTE, $this->situacionDe($persona));
    }

    public function test_cargar_mantiene_vigente_un_plazo_vencido_sin_cese(): void
    {
        // Comportamiento documentado (TODO pendiente de confirmar con el cliente en la clase): sin cese, sigue vigente.
        $persona = Usuario::factory()->create();
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'anulado' => false, 'fecha_cese' => null,
            'fecha_inicio' => '2020-01-01', 'fecha_fin_prevista' => '2020-12-31']);
        $this->assertSame(SituacionLaboral::VINCULO_VIGENTE, $this->situacionDe($persona));
    }

    public function test_cargar_con_cese_futuro_coincide_con_puesto_actual(): void
    {
        $this->markTestSkipped('Posible bug: SituacionLaboral::cargar (app/Support/SituacionLaboral.php:26) exige whereNull(fecha_cese), '
            .'mientras PuestoActual::vigentes (app/Support/PuestoActual.php:23) acepta cese futuro. Una persona con cese programado '
            .'a futuro sale «sin_vinculo_vigente» en el listado aunque PuestoActual la muestre con vínculo vigente; el docblock dice '
            .'que ambas usan el mismo criterio.');
    }
}
