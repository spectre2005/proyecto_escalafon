<?php

namespace Tests\Unit\Support;

use App\Models\ParametroSistema;
use App\Support\TiempoExperiencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Casos de calcular() y configuracion() que no cubre tests/Feature/TiempoExperienciaTest. */
class TiempoExperienciaTest extends TestCase
{
    use RefreshDatabase;

    private const CORTE = '2024-12-31';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function config(bool $pendientes = false): array
    {
        return ['incluir_pendientes' => $pendientes, 'dias_por_mes' => 30, 'dias_por_anio' => 360, 'hueco_largo_dias' => 90];
    }

    private function periodo(string $id, string $inicio, ?string $fin, array $datos = []): array
    {
        return ['id' => $id, 'fecha_inicio' => $inicio, 'fecha_fin' => $fin, 'sector' => 'publico', 'estado' => 'aceptado',
            'es_ejercicio_profesional' => true, 'es_docencia_universitaria' => false, ...$datos];
    }

    private function calcular(array $periodos, string $corte = self::CORTE, ?array $config = null): array
    {
        return TiempoExperiencia::calcular(collect($periodos), $corte, $config ?? $this->config());
    }

    private function parametro(string $clave, string $valor): void
    {
        ParametroSistema::query()->where('clave', $clave)->delete();
        ParametroSistema::factory()->create(['clave' => $clave, 'valor' => $valor, 'tipo_dato' => 'integer']);
    }

    private function assertConfiguracionInvalida(string $mensaje): void
    {
        try {
            TiempoExperiencia::configuracion();
            $this->fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame([$mensaje], $e->errors()['parametros']);
        }
    }

    public function test_sin_periodos_todo_en_cero(): void
    {
        $r = $this->calcular([]);
        $this->assertSame(['dias_totales' => 0, 'anios' => 0, 'meses' => 0, 'dias' => 0, 'anios_decimal' => 0.0, 'anios_decimal_sin_redondear' => 0], $r['total']);
        $this->assertSame([], $r['periodos']);
        $this->assertSame([], $r['huecos']);
        $this->assertSame([], $r['solapamientos']);
        $this->assertSame(0, $r['periodos_invalidos']);
        $this->assertSame(0, $r['sin_clasificar']);
        $this->assertSame(self::CORTE, $r['fecha_corte']);
    }

    public function test_un_anio_exacto_de_dias_configurados(): void
    {
        // 2024-01-01 a 2024-12-25 = 360 días inclusivos.
        $r = $this->calcular([$this->periodo('a', '2024-01-01', '2024-12-25')]);
        $this->assertSame(['dias_totales' => 360, 'anios' => 1, 'meses' => 0, 'dias' => 0, 'anios_decimal' => 1.0, 'anios_decimal_sin_redondear' => 1], $r['total']);
    }

    public function test_un_periodo_que_termina_despues_del_corte_se_cuenta_hasta_el_corte(): void
    {
        $r = $this->calcular([$this->periodo('a', '2024-12-01', '2025-06-30')]);
        $this->assertSame(31, $r['total']['dias_totales']);
        $this->assertSame(self::CORTE, $r['periodos'][0]['hasta']);
    }

    public function test_un_periodo_que_empieza_el_dia_del_corte_cuenta_un_dia(): void
    {
        $this->assertSame(1, $this->calcular([$this->periodo('a', self::CORTE, null)])['total']['dias_totales']);
    }

    public function test_periodos_invalidos_se_excluyen_y_se_cuentan(): void
    {
        $r = $this->calcular([
            $this->periodo('valido', '2024-01-01', '2024-01-10'),
            $this->periodo('futuro', '2025-01-01', '2025-01-10'),
            $this->periodo('invertido', '2024-02-10', '2024-02-01'),
            $this->periodo('sin sector', '2024-03-01', '2024-03-10', ['sector' => null]),
            $this->periodo('otro sector', '2024-04-01', '2024-04-10', ['sector' => 'independiente']),
        ]);
        $this->assertSame(10, $r['total']['dias_totales']);
        $this->assertSame(4, $r['periodos_invalidos']);
        $this->assertSame(['valido'], array_column($r['periodos'], 'id'));
    }

    public function test_cada_periodo_indica_si_cuenta_segun_su_estado(): void
    {
        $r = $this->calcular([
            $this->periodo('aceptado', '2024-01-01', '2024-01-10'),
            $this->periodo('pendiente', '2024-02-01', '2024-02-10', ['estado' => 'pendiente']),
            $this->periodo('observado', '2024-03-01', '2024-03-10', ['estado' => 'observado']),
        ], config: $this->config(true));
        $this->assertSame(['aceptado' => true, 'pendiente' => true, 'observado' => false], array_column($r['periodos'], 'cuenta', 'id'));
        $this->assertSame(20, $r['total']['dias_totales']);
    }

    public function test_los_huecos_se_calculan_sobre_todos_los_periodos_validos_aunque_no_cuenten(): void
    {
        $r = $this->calcular([
            $this->periodo('a', '2024-01-01', '2024-01-10'),
            $this->periodo('b', '2024-01-11', '2024-01-20', ['estado' => 'observado']),
            $this->periodo('c', '2024-01-21', '2024-01-31'),
        ]);
        $this->assertSame([], $r['huecos']);
        $this->assertSame(21, $r['total']['dias_totales']);
    }

    public function test_un_hueco_de_un_dia_entre_periodos(): void
    {
        $r = $this->calcular([$this->periodo('a', '2024-01-01', '2024-01-10'), $this->periodo('b', '2024-01-12', '2024-01-20')]);
        $this->assertSame([['desde' => '2024-01-11', 'hasta' => '2024-01-11', 'dias' => 1, 'largo' => false]], $r['huecos']);
    }

    public function test_solapamiento_de_periodos_superpuestos_contenidos_e_identicos(): void
    {
        $r = $this->calcular([
            $this->periodo('a', '2024-01-01', '2024-01-31'),
            $this->periodo('b', '2024-01-20', '2024-02-10'),
            $this->periodo('c', '2024-01-05', '2024-01-06'),
        ]);
        $this->assertSame([
            ['periodos' => ['a', 'c'], 'desde' => '2024-01-05', 'hasta' => '2024-01-06', 'dias' => 2],
            ['periodos' => ['a', 'b'], 'desde' => '2024-01-20', 'hasta' => '2024-01-31', 'dias' => 12],
        ], $r['solapamientos']);

        $identicos = $this->calcular([$this->periodo('a', '2024-01-01', '2024-01-10'), $this->periodo('b', '2024-01-01', '2024-01-10')]);
        $this->assertSame(10, $identicos['solapamientos'][0]['dias']);
    }

    public function test_periodos_consecutivos_o_de_un_dia_que_se_tocan_no_se_solapan(): void
    {
        $this->assertSame([], $this->calcular([$this->periodo('a', '2024-01-01', '2024-01-10'), $this->periodo('b', '2024-01-11', '2024-01-20')])['solapamientos']);
        $mismoDia = $this->calcular([$this->periodo('a', '2024-01-01', '2024-01-10'), $this->periodo('b', '2024-01-10', '2024-01-10')]);
        $this->assertSame(1, $mismoDia['solapamientos'][0]['dias']);
    }

    public function test_un_periodo_abierto_se_solapa_hasta_el_corte(): void
    {
        $r = $this->calcular([$this->periodo('a', '2024-12-01', null), $this->periodo('b', '2024-12-20', '2025-03-01')]);
        $this->assertSame([['periodos' => ['a', 'b'], 'desde' => '2024-12-20', 'hasta' => self::CORTE, 'dias' => 12]], $r['solapamientos']);
    }

    public function test_sin_clasificar_cuenta_solo_periodos_contados_con_indicadores_nulos(): void
    {
        $r = $this->calcular([
            $this->periodo('a', '2024-01-01', '2024-01-10', ['es_ejercicio_profesional' => null]),
            $this->periodo('b', '2024-02-01', '2024-02-10', ['es_docencia_universitaria' => null]),
            $this->periodo('c', '2024-03-01', '2024-03-10', ['es_ejercicio_profesional' => null, 'estado' => 'observado']),
        ]);
        $this->assertSame(2, $r['sin_clasificar']);
        // Un indicador nulo no suma al indicador.
        $this->assertSame(10, $r['indicadores']['anios_ejercicio_profesional']['dias_totales']);
    }

    public function test_sin_corte_usa_el_dia_de_lima(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-01-01 03:00:00', 'UTC')); // 31/12/2024 en Lima.
        $r = TiempoExperiencia::calcular(collect([$this->periodo('a', '2024-12-31', null)]), null, $this->config());
        $this->assertSame('2024-12-31', $r['fecha_corte']);
        $this->assertSame(1, $r['total']['dias_totales']);
    }

    public function test_configuracion_por_defecto(): void
    {
        $this->assertSame(['incluir_pendientes' => false, 'dias_por_mes' => 30, 'dias_por_anio' => 360, 'hueco_largo_dias' => 90], TiempoExperiencia::configuracion());
    }

    public function test_configuracion_sin_parametros_usa_config_legajo(): void
    {
        ParametroSistema::query()->whereIn('clave', ['exp_dias_por_mes', 'exp_dias_por_anio', 'exp_hueco_largo_dias', 'exp_incluir_pendientes'])->delete();
        config(['legajo.exp_dias_por_mes' => 31, 'legajo.exp_dias_por_anio' => 365, 'legajo.exp_hueco_largo_dias' => 60]);
        $this->assertSame(['incluir_pendientes' => false, 'dias_por_mes' => 31, 'dias_por_anio' => 365, 'hueco_largo_dias' => 60], TiempoExperiencia::configuracion());
    }

    public function test_configuracion_rechaza_inclusion_de_pendientes_distinta_de_0_o_1(): void
    {
        $this->parametro('exp_incluir_pendientes', 'si');
        $this->assertConfiguracionInvalida('El parámetro de inclusión de pendientes debe ser 0 o 1.');
    }

    public function test_configuracion_rechaza_valores_no_enteros_o_no_positivos(): void
    {
        foreach (['0', '-5', '30.5', 'treinta', ''] as $valor) {
            $this->parametro('exp_hueco_largo_dias', $valor);
            $this->assertConfiguracionInvalida('Los parámetros de tiempo de experiencia deben ser enteros positivos.');
        }
    }

    public function test_configuracion_acepta_dias_por_anio_iguales_a_dias_por_mes_pero_no_menores(): void
    {
        $this->parametro('exp_dias_por_mes', '30');
        $this->parametro('exp_dias_por_anio', '30');
        $this->assertSame(30, TiempoExperiencia::configuracion()['dias_por_anio']);
        $this->parametro('exp_dias_por_anio', '29');
        $this->assertConfiguracionInvalida('Los días por año no pueden ser menores que los días por mes.');
    }

    public function test_configuracion_rechaza_parametros_duplicados(): void
    {
        ParametroSistema::factory()->create(['clave' => 'exp_dias_por_anio', 'valor' => '360', 'tipo_dato' => 'integer']);
        $this->assertConfiguracionInvalida('Hay parámetros de experiencia duplicados. Administración debe revisar su configuración.');
    }
}
