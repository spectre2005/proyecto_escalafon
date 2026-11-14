<?php

namespace Tests\Unit\Support;

use App\Support\ResumenSeccion03;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResumenSeccion03Test extends TestCase
{
    private function doc(string $estado, bool $subsanado = false): array
    {
        return ['estado' => $estado, 'subsanado' => $subsanado];
    }

    /** @return array<string, array{string, string}> */
    public static function estadosDocumento(): array
    {
        return [
            'aceptado' => ['aceptado', 'aceptado'], 'verificado' => ['verificado', 'aceptado'],
            'observado' => ['observado', 'observado'], 'falso' => ['falso', 'observado'],
            'anulado' => ['anulado', 'anulado'], 'pendiente' => ['pendiente', 'pendiente'],
            'cargado' => ['cargado', 'pendiente'], 'desconocido' => ['otro', 'pendiente'],
        ];
    }

    #[DataProvider('estadosDocumento')]
    public function test_estado_documento_agrupa_los_estados_originales(string $original, string $esperado): void
    {
        $this->assertSame($esperado, ResumenSeccion03::estadoDocumento($original));
    }

    public function test_sin_documentos_vigentes_el_estado_depende_de_la_exigencia(): void
    {
        $this->assertSame('falta', ResumenSeccion03::estado(true, false, collect()));
        $this->assertSame('falta', ResumenSeccion03::estado(true, true, collect()));
        $this->assertSame('por_determinar', ResumenSeccion03::estado(null, true, collect()));
        $this->assertSame('opcional', ResumenSeccion03::estado(false, true, collect()));
        $this->assertSame('no_aplica', ResumenSeccion03::estado(false, false, collect()));
    }

    public function test_los_anulados_y_subsanados_no_cuentan_como_vigentes(): void
    {
        $this->assertSame('falta', ResumenSeccion03::estado(true, false, collect([$this->doc('anulado'), $this->doc('aceptado', true)])));
    }

    public function test_observado_tiene_prioridad_sobre_pendiente_y_aceptado(): void
    {
        $this->assertSame('observado', ResumenSeccion03::estado(true, false, collect([$this->doc('aceptado'), $this->doc('pendiente'), $this->doc('observado')])));
        $this->assertSame('pendiente', ResumenSeccion03::estado(true, false, collect([$this->doc('aceptado'), $this->doc('pendiente')])));
        $this->assertSame('aceptado', ResumenSeccion03::estado(false, false, collect([$this->doc('aceptado'), $this->doc('observado', true)])));
    }

    public function test_total_calcula_porcentaje_de_exigidos_aceptados_y_conteos(): void
    {
        $r = ResumenSeccion03::total(collect([
            ['exigido' => true, 'estado' => 'aceptado'], ['exigido' => true, 'estado' => 'aceptado'],
            ['exigido' => true, 'estado' => 'falta'], ['exigido' => false, 'estado' => 'aceptado'],
            ['exigido' => false, 'estado' => 'pendiente'], ['exigido' => true, 'estado' => 'observado'],
            ['exigido' => null, 'estado' => 'por_determinar'],
        ]));

        $this->assertSame(['exigidos' => 4, 'aceptados' => 2, 'porcentaje' => 50, 'pendientes' => 1, 'observados' => 1, 'faltantes' => 1, 'por_determinar' => 1], $r);
    }

    public function test_total_redondea_el_porcentaje(): void
    {
        $r = ResumenSeccion03::total(collect([
            ['exigido' => true, 'estado' => 'aceptado'], ['exigido' => true, 'estado' => 'aceptado'], ['exigido' => true, 'estado' => 'falta'],
        ]));

        $this->assertSame(67, $r['porcentaje']);
    }

    public function test_total_sin_exigidos_es_cien_por_ciento(): void
    {
        $this->assertSame(100, ResumenSeccion03::total(collect([['exigido' => false, 'estado' => 'opcional']]))['porcentaje']);
        $this->assertSame(100, ResumenSeccion03::total(collect())['porcentaje']);
    }
}
