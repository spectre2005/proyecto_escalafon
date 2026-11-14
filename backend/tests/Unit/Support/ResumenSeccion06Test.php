<?php

namespace Tests\Unit\Support;

use App\Support\ResumenSeccion06;
use PHPUnit\Framework\TestCase;

class ResumenSeccion06Test extends TestCase
{
    private function item(?bool $exigido, string $estado, array $documentos = []): array
    {
        return ['exigido' => $exigido, 'estado' => $estado, 'documentos' => $documentos];
    }

    private function doc(string $estado, bool $subsanado = false): array
    {
        return ['estado' => $estado, 'subsanado' => $subsanado];
    }

    public function test_sin_documentos_el_porcentaje_es_nulo_y_no_cien(): void
    {
        $r = ResumenSeccion06::total(collect([$this->item(true, 'falta'), $this->item(null, 'por_determinar')]));

        $this->assertSame(['registrados' => 0, 'aceptados' => 0, 'pendientes' => 0, 'observados' => 0, 'faltantes' => 1, 'por_determinar' => 1, 'porcentaje' => null], $r);
    }

    public function test_cuenta_los_documentos_vigentes_de_todos_los_items(): void
    {
        $r = ResumenSeccion06::total(collect([
            $this->item(true, 'aceptado', [$this->doc('aceptado'), $this->doc('pendiente')]),
            $this->item(false, 'observado', [$this->doc('observado'), $this->doc('aceptado')]),
        ]));

        $this->assertSame(4, $r['registrados']);
        $this->assertSame(2, $r['aceptados']);
        $this->assertSame(1, $r['pendientes']);
        $this->assertSame(1, $r['observados']);
        $this->assertSame(50, $r['porcentaje']);
    }

    public function test_excluye_anulados_y_subsanados(): void
    {
        $r = ResumenSeccion06::total(collect([
            $this->item(true, 'aceptado', [$this->doc('aceptado'), $this->doc('anulado'), $this->doc('observado', true)]),
        ]));

        $this->assertSame(1, $r['registrados']);
        $this->assertSame(0, $r['observados']);
        $this->assertSame(100, $r['porcentaje']);
    }

    public function test_redondea_el_porcentaje(): void
    {
        $r = ResumenSeccion06::total(collect([$this->item(true, 'pendiente', [$this->doc('aceptado'), $this->doc('pendiente'), $this->doc('pendiente')])]));

        $this->assertSame(33, $r['porcentaje']);
    }
}
