<?php

namespace Tests\Unit\Support;

use App\Support\ResumenSeccion02;
use PHPUnit\Framework\TestCase;

class ResumenSeccion02Test extends TestCase
{
    private function tipo(?bool $exigido, bool $cargado = false, bool $recepcionado = false, bool $verificado = false, string $nombre = 'Tipo'): array
    {
        return ['literal' => 'a', 'nombre' => $nombre, 'exigido' => $exigido, 'cargado' => $cargado, 'recepcionado' => $recepcionado, 'verificado' => $verificado];
    }

    public function test_subseccion_que_no_aplica_es_no_aplica_aunque_tenga_datos(): void
    {
        $r = ResumenSeccion02::subseccion(false, [$this->tipo(true, true)], 'completo');

        $this->assertSame('no_aplica', $r['estado']);
    }

    public function test_sin_datos_ni_documentos_es_sin_datos(): void
    {
        $r = ResumenSeccion02::subseccion(true, [$this->tipo(true), $this->tipo(false)], 'ninguno');

        $this->assertSame(['estado' => 'sin_datos', 'exigidos' => 1, 'exigidos_cargados' => 0, 'exigidos_recepcionados' => 0, 'exigidos_verificados' => 0], $r);
    }

    public function test_un_documento_no_exigido_cargado_basta_para_que_deje_de_estar_sin_datos(): void
    {
        $r = ResumenSeccion02::subseccion(true, [$this->tipo(true), $this->tipo(false, true)], 'ninguno');

        $this->assertSame('parcial', $r['estado']);
        $this->assertSame(0, $r['exigidos_cargados']);
    }

    public function test_datos_completos_y_todos_los_exigidos_cargados_es_completa(): void
    {
        $r = ResumenSeccion02::subseccion(true, [$this->tipo(true, true, true, false), $this->tipo(true, true), $this->tipo(null)], 'completo');

        $this->assertSame(['estado' => 'completa', 'exigidos' => 2, 'exigidos_cargados' => 2, 'exigidos_recepcionados' => 1, 'exigidos_verificados' => 0], $r);
    }

    public function test_datos_completos_sin_tipos_exigidos_es_completa(): void
    {
        $this->assertSame('completa', ResumenSeccion02::subseccion(true, [], 'completo')['estado']);
    }

    public function test_falta_un_exigido_o_los_datos_son_parciales_es_parcial(): void
    {
        $this->assertSame('parcial', ResumenSeccion02::subseccion(true, [$this->tipo(true, true), $this->tipo(true)], 'completo')['estado']);
        $this->assertSame('parcial', ResumenSeccion02::subseccion(true, [$this->tipo(true, true)], 'parcial')['estado']);
    }

    public function test_los_tipos_por_determinar_no_cuentan_como_exigidos(): void
    {
        $r = ResumenSeccion02::subseccion(true, [$this->tipo(null, true, true, true)], 'completo');

        $this->assertSame(0, $r['exigidos']);
        $this->assertSame(0, $r['exigidos_verificados']);
    }

    public function test_total_solo_suma_las_subsecciones_que_aplican(): void
    {
        $r = ResumenSeccion02::total([
            ['aplica' => true, 'tipos' => [$this->tipo(true, true, true, true, 'Contrato'), $this->tipo(true, false, false, false, 'Acta'), $this->tipo(null)]],
            ['aplica' => false, 'tipos' => [$this->tipo(true), $this->tipo(true)]],
            ['aplica' => true, 'tipos' => [$this->tipo(false, true)]],
        ]);

        $this->assertSame(4, $r['tipos']);
        $this->assertSame(2, $r['pendientes']);
        $this->assertSame(1, $r['exigidos_cargados']);
        $this->assertSame(1, $r['exigidos_recepcionados']);
        $this->assertSame(1, $r['exigidos_verificados']);
        $this->assertSame(1, $r['por_determinar']);
        $this->assertSame([
            ['literal' => 'a', 'nombre' => 'Contrato', 'cargado' => true, 'recepcionado' => true, 'verificado' => true],
            ['literal' => 'a', 'nombre' => 'Acta', 'cargado' => false, 'recepcionado' => false, 'verificado' => false],
        ], $r['exigidos']);
    }

    public function test_total_sin_subsecciones_devuelve_ceros(): void
    {
        $this->assertSame(['tipos' => 0, 'pendientes' => 0, 'exigidos' => [], 'exigidos_cargados' => 0, 'exigidos_recepcionados' => 0,
            'exigidos_verificados' => 0, 'por_determinar' => 0], ResumenSeccion02::total([]));
    }
}
