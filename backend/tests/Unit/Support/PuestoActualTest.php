<?php

namespace Tests\Unit\Support;

use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\PuestoActual;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PuestoActualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'America/Lima'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function unidad(string $nombre): UnidadOrganica
    {
        return (new UnidadOrganica)->forceFill(['nombre' => $nombre]);
    }

    private function vinculo(string $puesto, array $datos = [], ?string $unidad = null): VinculoLaboral
    {
        $vinculo = (new VinculoLaboral)->forceFill(['nombre_puesto' => $puesto, 'anulado' => false, 'fecha_inicio' => '2026-01-01',
            'fecha_fin_prevista' => null, 'fecha_cese' => null, 'regimen_laboral' => 'carrera', ...$datos]);
        $vinculo->setRelation('vinculoAdendas', new EloquentCollection);
        $vinculo->setRelation('unidadOrganica', $unidad ? $this->unidad($unidad) : null);

        return $vinculo;
    }

    /** Persona en memoria con sus vínculos abiertos ya cargados (en el orden de vigentes(): inicio más reciente primero). */
    private function persona(array $vinculos, array $datos = [], ?string $unidad = null): Usuario
    {
        $persona = (new Usuario)->forceFill(['nombre_puesto' => null, 'unidad_organica_id' => null, ...$datos]);
        $persona->setRelation('vinculosLaborales', new EloquentCollection($vinculos));
        $persona->setRelation('unidadOrganica', $unidad ? $this->unidad($unidad) : null);

        return $persona;
    }

    public function test_un_vinculo_vigente_da_el_puesto_y_la_unidad(): void
    {
        $resultado = PuestoActual::de($this->persona([$this->vinculo('Analista', [], 'Oficina de RR. HH.')]));
        $this->assertSame(['nombre_puesto' => 'Analista', 'unidad_organica' => 'Oficina de RR. HH.', 'origen' => 'vinculo', 'vinculos_vigentes' => 1,
            'situacion' => ['codigo' => 'vigente', 'texto' => 'Vigente a la fecha']], $resultado);
    }

    public function test_prefiere_el_vigente_aunque_haya_uno_abierto_mas_reciente_por_iniciar(): void
    {
        $resultado = PuestoActual::de($this->persona([
            $this->vinculo('Futuro', ['fecha_inicio' => '2026-07-01']),
            $this->vinculo('Actual'),
        ]));
        $this->assertSame('Actual', $resultado['nombre_puesto']);
        $this->assertSame(1, $resultado['vinculos_vigentes']);
    }

    public function test_cuenta_todos_los_vigentes_y_muestra_el_primero(): void
    {
        $resultado = PuestoActual::de($this->persona([$this->vinculo('Primero', ['fecha_inicio' => '2026-05-01']), $this->vinculo('Segundo')]));
        $this->assertSame('Primero', $resultado['nombre_puesto']);
        $this->assertSame(2, $resultado['vinculos_vigentes']);
    }

    public function test_sin_vigentes_muestra_el_abierto_mas_reciente_sin_presentarlo_como_vigente(): void
    {
        $resultado = PuestoActual::de($this->persona([
            $this->vinculo('Vencido', ['fecha_fin_prevista' => '2026-06-14']),
            $this->vinculo('Más antiguo', ['fecha_inicio' => '2025-01-01', 'fecha_fin_prevista' => '2025-12-31']),
        ]));
        $this->assertSame('Vencido', $resultado['nombre_puesto']);
        $this->assertSame('vinculo', $resultado['origen']);
        $this->assertSame(0, $resultado['vinculos_vigentes']);
        $this->assertSame('no_determinada', $resultado['situacion']['codigo']);
    }

    public function test_un_vinculo_por_iniciar_se_muestra_como_programado(): void
    {
        $resultado = PuestoActual::de($this->persona([$this->vinculo('Futuro', ['fecha_inicio' => '2026-06-16'])]));
        $this->assertSame('programado', $resultado['situacion']['codigo']);
        $this->assertSame(0, $resultado['vinculos_vigentes']);
    }

    public function test_un_vinculo_sin_unidad_da_unidad_nula(): void
    {
        $this->assertNull(PuestoActual::de($this->persona([$this->vinculo('Analista')]))['unidad_organica']);
    }

    public function test_sin_vinculos_usa_los_datos_historicos_de_la_persona(): void
    {
        $resultado = PuestoActual::de($this->persona([], ['nombre_puesto' => 'Secretaria', 'unidad_organica_id' => 'u1'], 'Facultad de Ingeniería'));
        $this->assertSame(['nombre_puesto' => 'Secretaria', 'unidad_organica' => 'Facultad de Ingeniería', 'origen' => 'persona', 'vinculos_vigentes' => 0, 'situacion' => null], $resultado);
    }

    public function test_sin_vinculos_y_solo_unidad_tambien_es_origen_persona(): void
    {
        $resultado = PuestoActual::de($this->persona([], ['unidad_organica_id' => 'u1'], 'Facultad de Ingeniería'));
        $this->assertNull($resultado['nombre_puesto']);
        $this->assertSame('persona', $resultado['origen']);
    }

    public function test_sin_unidad_organica_id_no_usa_la_relacion_cargada(): void
    {
        $resultado = PuestoActual::de($this->persona([], ['nombre_puesto' => 'Secretaria'], 'Unidad suelta'));
        $this->assertNull($resultado['unidad_organica']);
    }

    public function test_sin_vinculos_ni_datos_el_origen_es_nulo(): void
    {
        $this->assertSame(['nombre_puesto' => null, 'unidad_organica' => null, 'origen' => null, 'vinculos_vigentes' => 0, 'situacion' => null],
            PuestoActual::de($this->persona([])));
        $this->assertNull(PuestoActual::de($this->persona([], ['nombre_puesto' => '']))['origen']);
    }

    public function test_vigentes_carga_solo_abiertos_y_ordena_por_inicio_mas_reciente(): void
    {
        $persona = Usuario::factory()->create();
        $crear = fn (string $puesto, array $datos = []): VinculoLaboral => VinculoLaboral::factory()->create(['usuario_id' => $persona->id,
            'nombre_puesto' => $puesto, 'anulado' => false, 'fecha_cese' => null, 'fecha_inicio' => '2026-01-01', ...$datos]);
        $crear('Antiguo', ['fecha_inicio' => '2025-01-01']);
        $crear('Reciente', ['fecha_inicio' => '2026-03-01']);
        $crear('Cese hoy', ['fecha_inicio' => '2026-02-01', 'fecha_cese' => '2026-06-15']);
        $crear('Cese futuro', ['fecha_inicio' => '2026-01-15', 'fecha_cese' => '2026-12-31']);
        $crear('Cese ayer', ['fecha_cese' => '2026-06-14']);
        $crear('Anulado', ['anulado' => true]);

        $puestos = PuestoActual::vigentes($persona->vinculosLaborales())->pluck('nombre_puesto')->all();
        $this->assertSame(['Reciente', 'Cese hoy', 'Cese futuro', 'Antiguo'], $puestos);
    }

    public function test_de_consulta_la_base_si_los_vinculos_no_estan_cargados(): void
    {
        $persona = Usuario::factory()->create(['nombre_puesto' => 'Histórico']);
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'nombre_puesto' => 'Del vínculo', 'anulado' => false, 'fecha_cese' => null, 'fecha_inicio' => '2026-01-01']);
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'nombre_puesto' => 'Anulado', 'anulado' => true, 'fecha_cese' => null, 'fecha_inicio' => '2026-05-01']);

        $resultado = PuestoActual::de($persona->fresh());
        $this->assertSame('Del vínculo', $resultado['nombre_puesto']);
        $this->assertSame('vinculo', $resultado['origen']);
    }
}
