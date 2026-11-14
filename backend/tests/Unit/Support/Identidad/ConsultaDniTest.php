<?php

namespace Tests\Unit\Support\Identidad;

use App\Support\Identidad\ConsultaDni;
use App\Support\Identidad\ConsultaDniFactiliza;
use App\Support\Identidad\ConsultaDniSimulada;
use App\Support\Identidad\ResultadoIdentidad;
use PHPUnit\Framework\TestCase;

/** Contrato ConsultaDni: lo que cada proveedor declara de sí mismo (sin consultar ningún servicio). */
class ConsultaDniTest extends TestCase
{
    public function test_los_proveedores_del_sistema_implementan_el_contrato(): void
    {
        $this->assertInstanceOf(ConsultaDni::class, new ConsultaDniSimulada);
        $this->assertInstanceOf(ConsultaDni::class, new ConsultaDniFactiliza);
    }

    public function test_ningun_proveedor_actual_se_declara_oficial(): void
    {
        $this->assertFalse((new ConsultaDniSimulada)->esOficial());
        $this->assertFalse((new ConsultaDniFactiliza)->esOficial());
    }

    public function test_solo_el_simulado_se_declara_simulado(): void
    {
        $this->assertTrue((new ConsultaDniSimulada)->esSimulado());
        $this->assertFalse((new ConsultaDniFactiliza)->esSimulado());
    }

    public function test_cada_proveedor_tiene_un_nombre_distinto(): void
    {
        $this->assertSame('simulado', (new ConsultaDniSimulada)->nombre());
        $this->assertSame('factiliza', (new ConsultaDniFactiliza)->nombre());
    }

    public function test_un_proveedor_oficial_puede_implementar_el_contrato(): void
    {
        $oficial = new class implements ConsultaDni
        {
            public function nombre(): string
            {
                return 'oficial';
            }

            public function esOficial(): bool
            {
                return true;
            }

            public function esSimulado(): bool
            {
                return false;
            }

            public function consultar(string $dni): ResultadoIdentidad
            {
                return ResultadoIdentidad::sinCoincidencia();
            }
        };

        $this->assertTrue($oficial->esOficial());
        $this->assertFalse($oficial->esSimulado());
        $this->assertFalse($oficial->consultar('12345678')->encontrado);
    }
}
