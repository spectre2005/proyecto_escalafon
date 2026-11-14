<?php

namespace Tests\Unit\Support;

use App\Support\ResumenSeccion04;
use PHPUnit\Framework\TestCase;

class ResumenSeccion04Test extends TestCase
{
    private function item(?bool $exigido, string $estado, array $documentos = []): array
    {
        return ['exigido' => $exigido, 'estado' => $estado, 'documentos' => $documentos];
    }

    private function doc(string $estado, bool $subsanado = false): array
    {
        return ['estado' => $estado, 'subsanado' => $subsanado];
    }

    public function test_un_item_exigido_sin_certificados_espera_al_menos_uno(): void
    {
        $r = ResumenSeccion04::total(collect([$this->item(true, 'falta')]));

        $this->assertSame(1, $r['exigidos']);
        $this->assertSame(0, $r['aceptados']);
        $this->assertSame(0, $r['porcentaje']);
        $this->assertSame(1, $r['faltantes']);
    }

    public function test_cada_certificado_vigente_cuenta_como_una_experiencia_exigida(): void
    {
        $r = ResumenSeccion04::total(collect([
            $this->item(true, 'pendiente', [$this->doc('aceptado'), $this->doc('aceptado'), $this->doc('pendiente')]),
            $this->item(false, 'aceptado', [$this->doc('aceptado')]),
        ]));

        $this->assertSame(3, $r['exigidos']);
        $this->assertSame(2, $r['aceptados']);
        $this->assertSame(67, $r['porcentaje']);
        $this->assertSame(1, $r['pendientes']);
    }

    public function test_los_anulados_y_subsanados_no_suman_experiencias(): void
    {
        $r = ResumenSeccion04::total(collect([
            $this->item(true, 'aceptado', [$this->doc('aceptado'), $this->doc('anulado'), $this->doc('aceptado', true)]),
        ]));

        $this->assertSame(1, $r['exigidos']);
        $this->assertSame(1, $r['aceptados']);
        $this->assertSame(100, $r['porcentaje']);
    }

    public function test_sin_items_exigidos_es_cien_por_ciento_y_conserva_los_conteos_de_seccion03(): void
    {
        $r = ResumenSeccion04::total(collect([$this->item(null, 'por_determinar'), $this->item(false, 'observado', [$this->doc('observado')])]));

        $this->assertSame(0, $r['exigidos']);
        $this->assertSame(100, $r['porcentaje']);
        $this->assertSame(1, $r['por_determinar']);
        $this->assertSame(1, $r['observados']);
    }
}
