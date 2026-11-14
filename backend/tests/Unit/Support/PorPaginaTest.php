<?php

namespace Tests\Unit\Support;

use App\Support\PorPagina;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PorPaginaTest extends TestCase
{
    public function test_la_regla_por_defecto_admite_solo_10_15_y_20(): void
    {
        $this->assertSame([10, 15, 20], PorPagina::OPCIONES);
        $this->assertSame(10, PorPagina::POR_DEFECTO);
        $this->assertSame('sometimes|integer|in:10,15,20', PorPagina::regla());
    }

    public function test_la_regla_usa_las_opciones_propias_de_un_listado_con_excepcion(): void
    {
        $this->assertSame('sometimes|integer|in:25,50', PorPagina::regla([25, 50]));
    }

    public function test_sin_por_pagina_devuelve_el_valor_por_defecto(): void
    {
        $this->assertSame(10, PorPagina::de([]));
        $this->assertSame(10, PorPagina::de(['por_pagina' => null]));
        $this->assertSame(25, PorPagina::de([], 25));
    }

    public function test_con_por_pagina_devuelve_el_valor_como_entero(): void
    {
        $this->assertSame(15, PorPagina::de(['por_pagina' => 15]));
        $this->assertSame(20, PorPagina::de(['por_pagina' => '20']));
        $this->assertSame(20, PorPagina::de(['por_pagina' => '20'], 50));
    }

    /** @return array<string, array{mixed}> */
    public static function valoresRechazados(): array
    {
        return [
            'cero' => [0],
            'negativo' => [-10],
            'mayor que el máximo' => [21],
            'cien' => [100],
            'fuera de la lista' => [12],
            'no numérico' => ['abc'],
            'decimal' => ['10.5'],
            'arreglo' => [[10]],
        ];
    }

    #[DataProvider('valoresRechazados')]
    public function test_la_regla_rechaza_valores_fuera_de_la_lista_cerrada(mixed $valor): void
    {
        $this->assertTrue(Validator::make(['por_pagina' => $valor], ['por_pagina' => PorPagina::regla()])->fails());
    }

    public function test_la_regla_acepta_las_opciones_como_numero_o_texto_y_la_ausencia_del_campo(): void
    {
        foreach ([10, 15, 20, '15'] as $valor) {
            $this->assertTrue(Validator::make(['por_pagina' => $valor], ['por_pagina' => PorPagina::regla()])->passes(), (string) $valor);
        }
        $this->assertTrue(Validator::make([], ['por_pagina' => PorPagina::regla()])->passes());
    }

    public function test_por_pagina_nulo_se_rechaza_y_de_usa_el_valor_por_defecto(): void
    {
        // En una petición real ConvertEmptyStringsToNull convierte «por_pagina=» en null: la regla (sin nullable) lo rechaza.
        $this->assertTrue(Validator::make(['por_pagina' => null], ['por_pagina' => PorPagina::regla()])->fails());
        $this->assertSame(10, PorPagina::de(['por_pagina' => null]));
    }

    public function test_la_regla_con_opciones_propias_rechaza_las_opciones_comunes(): void
    {
        $this->assertTrue(Validator::make(['por_pagina' => 10], ['por_pagina' => PorPagina::regla([25, 50])])->fails());
        $this->assertTrue(Validator::make(['por_pagina' => 50], ['por_pagina' => PorPagina::regla([25, 50])])->passes());
    }
}
