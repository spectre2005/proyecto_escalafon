<?php

namespace Tests\Unit\Support;

use App\Models\ParametroSistema;
use App\Support\Parametros;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParametrosTest extends TestCase
{
    use RefreshDatabase;

    /** Algunas claves ya vienen sembradas por las migraciones: se crea o se reemplaza su valor. */
    private function parametro(string $clave, string $valor): void
    {
        ParametroSistema::query()->updateOrCreate(['clave' => $clave], ['valor' => $valor, 'tipo_dato' => 'entero', 'descripcion' => 'Prueba']);
    }

    public function test_sin_registro_en_parametros_sistema_usa_el_valor_de_config_legajo(): void
    {
        config(['legajo.clave_de_prueba' => 45]);

        $this->assertSame(45, Parametros::entero('clave_de_prueba'));
    }

    public function test_el_registro_numerico_de_parametros_sistema_tiene_prioridad_sobre_config(): void
    {
        config(['legajo.exp_dias_por_mes' => 30]);
        $this->parametro('exp_dias_por_mes', '31');

        $this->assertSame(31, Parametros::entero('exp_dias_por_mes'));
    }

    public function test_un_cero_guardado_es_un_valor_valido_y_no_cae_al_defecto(): void
    {
        config(['legajo.dias_aviso_vencimiento_vinculo' => 30]);
        $this->parametro('dias_aviso_vencimiento_vinculo', '0');

        $this->assertSame(0, Parametros::entero('dias_aviso_vencimiento_vinculo'));
    }

    public function test_un_valor_no_numerico_o_vacio_se_ignora_y_usa_config(): void
    {
        config(['legajo.tamano_maximo_pdf_kb' => 2048, 'legajo.exp_dias_por_anio' => 360]);
        $this->parametro('tamano_maximo_pdf_kb', 'dos megas');
        $this->parametro('exp_dias_por_anio', '');

        $this->assertSame(2048, Parametros::entero('tamano_maximo_pdf_kb'));
        $this->assertSame(360, Parametros::entero('exp_dias_por_anio'));
    }

    public function test_un_valor_decimal_se_trunca_a_entero(): void
    {
        $this->parametro('exp_hueco_largo_dias', '90.7');

        $this->assertSame(90, Parametros::entero('exp_hueco_largo_dias'));
    }

    public function test_una_clave_sin_registro_ni_configuracion_devuelve_cero(): void
    {
        $this->assertSame(0, Parametros::entero('clave_que_no_existe'));
    }
}
