<?php

namespace Tests\Unit\Support;

use App\Support\ResumenSeccion08;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class ResumenSeccion08Test extends TestCase
{
    private function doc(string $estado, bool $subsanado = false, array $extra = []): array
    {
        return ['estado' => $estado, 'subsanado' => $subsanado, ...$extra];
    }

    public function test_estado_no_aplica_tiene_prioridad(): void
    {
        $this->assertSame('no_aplica', ResumenSeccion08::estado(collect([$this->doc('observado')]), true));
    }

    public function test_sin_documentos_vigentes_es_opcional(): void
    {
        $this->assertSame('opcional', ResumenSeccion08::estado(collect()));
        $this->assertSame('opcional', ResumenSeccion08::estado(collect([$this->doc('anulado'), $this->doc('aceptado', true)])));
    }

    public function test_estado_prioriza_observado_luego_pendiente_luego_aceptado(): void
    {
        $this->assertSame('observado', ResumenSeccion08::estado(collect([$this->doc('aceptado'), $this->doc('observado'), $this->doc('pendiente')])));
        $this->assertSame('pendiente', ResumenSeccion08::estado(collect([$this->doc('aceptado'), $this->doc('pendiente')])));
        $this->assertSame('aceptado', ResumenSeccion08::estado(collect([$this->doc('aceptado')])));
    }

    public function test_sancion_sin_recursos_o_con_recursos_infundados_sigue_vigente(): void
    {
        $this->assertSame('vigente', ResumenSeccion08::estadoSancion(collect()));
        $this->assertSame('vigente', ResumenSeccion08::estadoSancion(collect([['resultado' => 'INFUNDADO'], ['resultado' => 'INFUNDADO']])));
        $this->assertSame('vigente', ResumenSeccion08::estadoSancion(collect([['resultado' => null]])));
    }

    public function test_sancion_con_recursos_solo_fundados_queda_sin_efecto(): void
    {
        $this->assertSame('dejada_sin_efecto', ResumenSeccion08::estadoSancion(collect([['resultado' => 'FUNDADO'], ['resultado' => 'FUNDADO']])));
    }

    public function test_sancion_con_resultados_mixtos_que_incluyen_fundado_queda_indeterminada(): void
    {
        $this->assertNull(ResumenSeccion08::estadoSancion(collect([['resultado' => 'FUNDADO'], ['resultado' => 'INFUNDADO']])));
    }

    private function items(): Collection
    {
        return collect([
            ['documentos' => [
                $this->doc('aceptado', false, ['grupo' => 'reconocimientos']),
                $this->doc('pendiente', false, ['grupo' => 'reconocimientos']),
                $this->doc('aceptado', false, ['grupo' => 'reconocimientos', 'estado' => 'anulado']),
            ]],
            ['documentos' => [
                $this->doc('aceptado', false, ['grupo' => 'sanciones', 'tipo_documento_codigo' => 'SAN_RESOLUCION_SANCION', 'estado_sancion' => 'vigente']),
                $this->doc('observado', false, ['grupo' => 'sanciones', 'tipo_documento_codigo' => 'SAN_RESOLUCION_SANCION', 'estado_sancion' => 'dejada_sin_efecto']),
                $this->doc('aceptado', false, ['grupo' => 'sanciones', 'tipo_documento_codigo' => 'SAN_RESOLUCION_RECURSO']),
                $this->doc('aceptado', true, ['grupo' => 'sanciones', 'tipo_documento_codigo' => 'SAN_RESOLUCION_SANCION', 'estado_sancion' => 'vigente']),
            ]],
        ]);
    }

    public function test_resumen_cuenta_reconocimientos_y_sanciones_vigentes_sin_anulados_ni_subsanados(): void
    {
        $this->assertSame(['reconocimientos' => 2, 'sanciones' => 2, 'vigentes' => 1, 'dejadas_sin_efecto' => 1, 'pendientes' => 1, 'observados' => 1],
            ResumenSeccion08::resumen($this->items(), true));
    }

    public function test_resumen_oculta_los_conteos_de_sanciones_sin_permiso(): void
    {
        $r = ResumenSeccion08::resumen($this->items(), false);

        $this->assertSame(2, $r['reconocimientos']);
        $this->assertNull($r['sanciones']);
        $this->assertNull($r['vigentes']);
        $this->assertNull($r['dejadas_sin_efecto']);
    }
}
