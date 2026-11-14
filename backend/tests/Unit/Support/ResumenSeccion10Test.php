<?php

namespace Tests\Unit\Support;

use App\Support\ResumenSeccion10;
use PHPUnit\Framework\TestCase;

class ResumenSeccion10Test extends TestCase
{
    private const HOY = '2026-11-13';

    private function doc(array $datos = []): array
    {
        return ['anulado' => false, 'subsanado' => false, 'archivo_disponible' => true, 'estado_original' => 'aceptado',
            'vence_en' => null, 'fecha_inicio' => null, 'fecha_emision' => '2026-01-10', 'numero_folios' => 1, ...$datos];
    }

    private function item(string $codigo, array $documentos): array
    {
        return ResumenSeccion10::item(['codigo' => $codigo, 'nombre' => 'Tipo'], collect($documentos), self::HOY);
    }

    public function test_sin_documentos_el_estado_es_no(): void
    {
        $r = $this->item('S10_REGLAMENTO', []);

        $this->assertSame('no', $r['estado']);
        $this->assertSame(0, $r['cantidad']);
        $this->assertNull($r['ultima_fecha']);
        $this->assertSame(0, $r['numero_folios']);
    }

    public function test_agrega_la_configuracion_del_tipo(): void
    {
        $r = $this->item('S10_EPS', []);

        $this->assertSame(['codigo' => 'S10_EPS', 'nombre' => 'Tipo'], array_intersect_key($r, ['codigo' => 1, 'nombre' => 1]));
        $this->assertSame('10.2', $r['subseccion']);
        $this->assertTrue($r['periodo']);
        $this->assertTrue($r['vigencia']);
        $this->assertTrue($r['entidad_eps']);

        $r = $this->item('S10_REGLAMENTO', []);
        $this->assertSame('10.1', $r['subseccion']);
        $this->assertFalse($r['periodo']);
    }

    public function test_un_documento_valido_sin_vencimiento_o_que_vence_hoy_esta_al_dia(): void
    {
        $this->assertSame('si', $this->item('S10_SEGURO', [$this->doc()])['estado']);
        $this->assertSame('si', $this->item('S10_SEGURO', [$this->doc(['vence_en' => self::HOY])])['estado']);
    }

    public function test_si_todos_los_validos_vencieron_el_estado_es_vencido(): void
    {
        $this->assertSame('vencido', $this->item('S10_SEGURO', [$this->doc(['vence_en' => '2026-11-12']), $this->doc(['vence_en' => '2025-01-01'])])['estado']);
    }

    public function test_uno_vencido_y_otro_vigente_esta_al_dia(): void
    {
        $this->assertSame('si', $this->item('S10_SEGURO', [$this->doc(['vence_en' => '2025-01-01']), $this->doc(['vence_en' => '2027-01-01'])])['estado']);
    }

    public function test_documentos_pendientes_observados_falsos_o_sin_archivo_dejan_el_estado_pendiente(): void
    {
        foreach (['pendiente', 'observado', 'falso'] as $estado) {
            $this->assertSame('pendiente', $this->item('S10_SEGURO', [$this->doc(['estado_original' => $estado])])['estado'], $estado);
        }
        $r = $this->item('S10_SEGURO', [$this->doc(['archivo_disponible' => false, 'numero_folios' => 3])]);
        $this->assertSame('pendiente', $r['estado']);
        $this->assertSame(0, $r['cantidad']);
        $this->assertSame(3, $r['numero_folios']);
    }

    public function test_los_anulados_y_subsanados_no_cuentan_pero_se_listan(): void
    {
        $r = $this->item('S10_SEGURO', [$this->doc(['anulado' => true]), $this->doc(['subsanado' => true])]);

        $this->assertSame('no', $r['estado']);
        $this->assertSame(0, $r['numero_folios']);
        $this->assertCount(2, $r['documentos']);
    }

    public function test_eps_con_inicio_futuro_no_esta_al_dia_pero_otro_tipo_si(): void
    {
        $futuro = $this->doc(['fecha_inicio' => '2026-12-01']);

        $this->assertSame('pendiente', $this->item('S10_EPS', [$futuro])['estado']);
        $this->assertSame('si', $this->item('S10_EPS', [$this->doc(['fecha_inicio' => self::HOY])])['estado']);
        $this->assertSame('si', $this->item('S10_SEGURO', [$futuro])['estado']);
    }

    public function test_cantidad_ultima_fecha_y_folios_de_los_documentos(): void
    {
        $r = $this->item('S10_CAPACITACIONES', [
            $this->doc(['fecha_emision' => '2026-03-01', 'numero_folios' => 2]),
            $this->doc(['fecha_emision' => '2026-07-15', 'numero_folios' => 4]),
            $this->doc(['fecha_emision' => '2026-10-01', 'numero_folios' => 5, 'anulado' => true]),
        ]);

        $this->assertSame(2, $r['cantidad']);
        $this->assertSame('2026-07-15', $r['ultima_fecha']);
        $this->assertSame(6, $r['numero_folios']);
    }

    public function test_folios_separa_sst_bienestar_y_sin_clasificar_sin_anulados_ni_subsanados(): void
    {
        $r = ResumenSeccion10::folios(collect([
            $this->doc(['subseccion' => '10.1', 'numero_folios' => 3]),
            $this->doc(['subseccion' => '10.2', 'numero_folios' => 2]),
            $this->doc(['subseccion' => null, 'numero_folios' => 1]),
            $this->doc(['subseccion' => '10.1', 'numero_folios' => 10, 'anulado' => true]),
            $this->doc(['subseccion' => '10.2', 'numero_folios' => 10, 'subsanado' => true]),
        ]));

        $this->assertSame(['sst' => 3, 'bienestar' => 2, 'total' => 6, 'sin_clasificar' => 1], $r);
    }

    public function test_folios_sin_documentos_son_cero(): void
    {
        $this->assertSame(['sst' => 0, 'bienestar' => 0, 'total' => 0, 'sin_clasificar' => 0], ResumenSeccion10::folios(collect()));
    }
}
