<?php

namespace Tests\Unit\Support;

use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use App\Support\VigenciaLaboral;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VigenciaLaboralTest extends TestCase
{
    use RefreshDatabase;

    private const FECHA = '2026-06-15';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Vínculo en memoria (sin BD) con sus adendas ya cargadas. */
    private function vinculo(array $datos = [], array $adendas = []): VinculoLaboral
    {
        $vinculo = (new VinculoLaboral)->forceFill(['anulado' => false, 'fecha_inicio' => '2026-01-01', 'fecha_fin_prevista' => null, 'fecha_cese' => null, ...$datos]);
        $vinculo->setRelation('vinculoAdendas', new EloquentCollection(array_map(
            fn (array $a): VinculoAdenda => (new VinculoAdenda)->forceFill(['anulado' => false, 'created_at' => '2026-01-01 08:00:00', ...$a]),
            $adendas,
        )));

        return $vinculo;
    }

    public function test_hoy_usa_el_dia_institucional_de_lima_y_no_el_de_utc(): void
    {
        // 03:00 UTC del 14 son las 22:00 del 13 en Lima.
        Carbon::setTestNow(Carbon::parse('2026-11-14 03:00:00', 'UTC'));
        $this->assertSame('2026-11-13', VigenciaLaboral::hoy());
    }

    public function test_fin_sin_adendas_es_la_fecha_fin_prevista(): void
    {
        $this->assertSame('2026-12-31', VigenciaLaboral::fin($this->vinculo(['fecha_fin_prevista' => '2026-12-31']))->format('Y-m-d'));
    }

    public function test_fin_es_nulo_sin_adendas_ni_fecha_fin_prevista(): void
    {
        $this->assertNull(VigenciaLaboral::fin($this->vinculo()));
    }

    public function test_fin_toma_la_adenda_no_anulada_de_inicio_mas_reciente(): void
    {
        $vinculo = $this->vinculo(['fecha_fin_prevista' => '2026-03-31'], [
            ['id' => 'a', 'fecha_inicio' => '2026-04-01', 'fecha_fin' => '2026-12-31'],
            // La última adenda puede acortar el plazo: manda la de inicio más reciente, no la de fin más lejano.
            ['id' => 'b', 'fecha_inicio' => '2026-05-01', 'fecha_fin' => '2026-08-31'],
            ['id' => 'c', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2027-12-31', 'anulado' => true],
        ]);
        $this->assertSame('2026-08-31', VigenciaLaboral::fin($vinculo)->format('Y-m-d'));
    }

    public function test_fin_desempata_por_fecha_de_registro_y_luego_por_id(): void
    {
        $porRegistro = $this->vinculo([], [
            ['id' => 'b', 'fecha_inicio' => '2026-04-01', 'fecha_fin' => '2026-10-31', 'created_at' => '2026-04-01 09:00:00'],
            ['id' => 'a', 'fecha_inicio' => '2026-04-01', 'fecha_fin' => '2026-11-30', 'created_at' => '2026-04-01 10:00:00'],
        ]);
        $this->assertSame('2026-11-30', VigenciaLaboral::fin($porRegistro)->format('Y-m-d'));

        $porId = $this->vinculo([], [
            ['id' => 'b', 'fecha_inicio' => '2026-04-01', 'fecha_fin' => '2026-10-31'],
            ['id' => 'a', 'fecha_inicio' => '2026-04-01', 'fecha_fin' => '2026-11-30'],
        ]);
        $this->assertSame('2026-10-31', VigenciaLaboral::fin($porId)->format('Y-m-d'));
    }

    public function test_fin_ignora_adendas_anuladas_y_vuelve_a_la_fecha_fin_prevista(): void
    {
        $vinculo = $this->vinculo(['fecha_fin_prevista' => '2026-03-31'], [
            ['id' => 'a', 'fecha_inicio' => '2026-04-01', 'fecha_fin' => '2026-12-31', 'anulado' => true],
        ]);
        $this->assertSame('2026-03-31', VigenciaLaboral::fin($vinculo)->format('Y-m-d'));
    }

    public function test_fin_con_adenda_sin_fecha_fin_cae_en_la_fecha_fin_prevista(): void
    {
        $vinculo = $this->vinculo(['fecha_fin_prevista' => '2026-03-31'], [
            ['id' => 'a', 'fecha_inicio' => '2026-04-01', 'fecha_fin' => null],
        ]);
        $this->assertSame('2026-03-31', VigenciaLaboral::fin($vinculo)->format('Y-m-d'));
    }

    public function test_vigente_en_el_camino_normal(): void
    {
        $this->assertTrue(VigenciaLaboral::vigente($this->vinculo(['fecha_fin_prevista' => '2026-12-31']), self::FECHA));
    }

    public function test_vigente_incluye_el_mismo_dia_de_inicio_cese_y_fin(): void
    {
        $this->assertTrue(VigenciaLaboral::vigente($this->vinculo(['fecha_inicio' => self::FECHA]), self::FECHA));
        $this->assertTrue(VigenciaLaboral::vigente($this->vinculo(['fecha_cese' => self::FECHA]), self::FECHA));
        $this->assertTrue(VigenciaLaboral::vigente($this->vinculo(['fecha_fin_prevista' => self::FECHA]), self::FECHA));
        $this->assertTrue(VigenciaLaboral::vigente($this->vinculo([], [['id' => 'a', 'fecha_inicio' => '2026-05-01', 'fecha_fin' => self::FECHA]]), self::FECHA));
    }

    public function test_no_vigente_un_dia_despues_del_cese_o_del_fin(): void
    {
        $this->assertFalse(VigenciaLaboral::vigente($this->vinculo(['fecha_cese' => '2026-06-14']), self::FECHA));
        $this->assertFalse(VigenciaLaboral::vigente($this->vinculo(['fecha_fin_prevista' => '2026-06-14']), self::FECHA));
    }

    public function test_no_vigente_si_inicia_al_dia_siguiente(): void
    {
        $this->assertFalse(VigenciaLaboral::vigente($this->vinculo(['fecha_inicio' => '2026-06-16']), self::FECHA));
    }

    public function test_no_vigente_si_esta_anulado_o_no_tiene_fecha_de_inicio(): void
    {
        $this->assertFalse(VigenciaLaboral::vigente($this->vinculo(['anulado' => true]), self::FECHA));
        $this->assertFalse(VigenciaLaboral::vigente($this->vinculo(['fecha_inicio' => null]), self::FECHA));
    }

    public function test_una_adenda_vigente_extiende_un_plazo_vencido(): void
    {
        $vinculo = $this->vinculo(['fecha_fin_prevista' => '2026-03-31'], [['id' => 'a', 'fecha_inicio' => '2026-04-01', 'fecha_fin' => '2026-12-31']]);
        $this->assertTrue(VigenciaLaboral::vigente($vinculo, self::FECHA));
    }

    public function test_un_cese_pasado_manda_aunque_la_adenda_siga_vigente(): void
    {
        $vinculo = $this->vinculo(['fecha_cese' => '2026-05-31'], [['id' => 'a', 'fecha_inicio' => '2026-04-01', 'fecha_fin' => '2026-12-31']]);
        $this->assertFalse(VigenciaLaboral::vigente($vinculo, self::FECHA));
    }

    public function test_vigente_sin_fecha_usa_hoy_en_lima(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 03:00:00', 'UTC')); // 30/06 en Lima.
        $this->assertTrue(VigenciaLaboral::vigente($this->vinculo(['fecha_fin_prevista' => '2026-06-30'])));
    }

    public function test_situacion_programado_con_fecha_de_inicio_en_texto(): void
    {
        $this->assertSame(['codigo' => 'programado', 'texto' => 'Inicio previsto el 16/06/2026'],
            VigenciaLaboral::situacion($this->vinculo(['fecha_inicio' => '2026-06-16']), self::FECHA));
    }

    public function test_situacion_concluido_con_cese_anterior_a_la_fecha(): void
    {
        $this->assertSame(['codigo' => 'concluido', 'texto' => 'Concluido el 14/06/2026 (cese registrado)'],
            VigenciaLaboral::situacion($this->vinculo(['fecha_cese' => '2026-06-14']), self::FECHA));
    }

    public function test_situacion_vigente_con_cese_el_mismo_dia(): void
    {
        $this->assertSame(['codigo' => 'vigente', 'texto' => 'Vigente a la fecha'],
            VigenciaLaboral::situacion($this->vinculo(['fecha_cese' => self::FECHA]), self::FECHA));
    }

    public function test_situacion_no_determinada_si_el_plazo_vencio_sin_cese(): void
    {
        $this->assertSame(['codigo' => 'no_determinada', 'texto' => 'No determinada: el plazo terminó el 14/06/2026 y no hay cese registrado'],
            VigenciaLaboral::situacion($this->vinculo(['fecha_fin_prevista' => '2026-06-14']), self::FECHA));
    }

    public function test_situacion_no_determinada_con_cese_futuro_no_dice_que_falte_el_cese(): void
    {
        $this->assertSame(['codigo' => 'no_determinada', 'texto' => 'No determinada: el plazo terminó el 31/05/2026'],
            VigenciaLaboral::situacion($this->vinculo(['fecha_fin_prevista' => '2026-05-31', 'fecha_cese' => '2026-12-31']), self::FECHA));
    }

    public function test_situacion_usa_la_adenda_para_el_fin_vencido(): void
    {
        $vinculo = $this->vinculo(['fecha_fin_prevista' => '2026-03-31'], [['id' => 'a', 'fecha_inicio' => '2026-04-01', 'fecha_fin' => '2026-05-31']]);
        $this->assertSame('No determinada: el plazo terminó el 31/05/2026 y no hay cese registrado', VigenciaLaboral::situacion($vinculo, self::FECHA)['texto']);
    }

    public function test_consulta_coincide_con_vigente_en_los_bordes(): void
    {
        $persona = Usuario::factory()->create();
        $crear = fn (array $datos): VinculoLaboral => VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'anulado' => false,
            'fecha_inicio' => '2026-01-01', 'fecha_fin_prevista' => null, 'fecha_cese' => null, ...$datos]);
        $casos = [
            'abierto' => [$crear([]), true],
            'inicia hoy' => [$crear(['fecha_inicio' => self::FECHA]), true],
            'inicia mañana' => [$crear(['fecha_inicio' => '2026-06-16']), false],
            'cese hoy' => [$crear(['fecha_cese' => self::FECHA]), true],
            'cese ayer' => [$crear(['fecha_cese' => '2026-06-14']), false],
            'fin hoy' => [$crear(['fecha_fin_prevista' => self::FECHA]), true],
            'fin ayer' => [$crear(['fecha_fin_prevista' => '2026-06-14']), false],
            'anulado' => [$crear(['anulado' => true]), false],
            'extendido por adenda' => [$crear(['fecha_fin_prevista' => '2026-03-31']), true],
            'adenda anulada' => [$crear(['fecha_fin_prevista' => '2026-03-31']), false],
            'acortado por adenda' => [$crear(['fecha_fin_prevista' => '2026-12-31']), false],
        ];
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $casos['extendido por adenda'][0]->id, 'fecha_inicio' => '2026-04-01', 'fecha_fin' => '2026-12-31']);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $casos['adenda anulada'][0]->id, 'fecha_inicio' => '2026-04-01', 'fecha_fin' => '2026-12-31', 'anulado' => true]);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $casos['acortado por adenda'][0]->id, 'fecha_inicio' => '2026-04-01', 'fecha_fin' => '2026-05-31']);

        $ids = VigenciaLaboral::consulta(VinculoLaboral::query(), self::FECHA)->pluck('id')->all();
        foreach ($casos as $nombre => [$vinculo, $esperado]) {
            $this->assertSame($esperado, in_array($vinculo->id, $ids, true), "consulta: {$nombre}");
            $this->assertSame($esperado, VigenciaLaboral::vigente($vinculo->fresh(), self::FECHA), "vigente: {$nombre}");
        }
    }
}
