<?php

namespace Tests\Unit\Support\Identidad;

use App\Support\Identidad\ConsultaDniSimulada;
use App\Support\Identidad\ProveedorNoDisponible;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class ConsultaDniSimuladaTest extends TestCase
{
    private string $entorno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entorno = $this->app['env'];
        // El simulado no usa la red: cualquier petición HTTP haría fallar la prueba.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->app['env'] = $this->entorno;
        parent::tearDown();
    }

    public function test_se_identifica_como_simulado_y_no_oficial(): void
    {
        $p = new ConsultaDniSimulada;

        $this->assertSame('simulado', $p->nombre());
        $this->assertFalse($p->esOficial());
        $this->assertTrue($p->esSimulado());
    }

    public function test_el_caso_con_ubicacion_y_direccion_devuelve_todos_los_datos(): void
    {
        $r = (new ConsultaDniSimulada)->consultar('00000001');

        $this->assertTrue($r->encontrado);
        $this->assertSame('Ana Lucía', $r->nombres);
        $this->assertSame('Demostración', $r->apellidoPaterno);
        $this->assertSame('Ficticia', $r->apellidoMaterno);
        $this->assertSame(['departamento' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA'], $r->ubicacion);
        $this->assertSame('JR. DEMOSTRACION 123', $r->direccion);
    }

    public function test_el_caso_sin_ubicacion_ni_direccion_las_deja_en_null(): void
    {
        $r = (new ConsultaDniSimulada)->consultar('00000002');

        $this->assertTrue($r->encontrado);
        $this->assertSame('Carlos Alberto', $r->nombres);
        $this->assertNull($r->ubicacion);
        $this->assertNull($r->direccion);
    }

    public function test_el_caso_sin_apellido_materno_lo_devuelve_null(): void
    {
        $r = (new ConsultaDniSimulada)->consultar('00000003');

        $this->assertTrue($r->encontrado);
        $this->assertNull($r->apellidoMaterno);
    }

    public function test_cada_caso_de_demostracion_coincide_con_su_definicion(): void
    {
        foreach (ConsultaDniSimulada::CASOS as $dni => $caso) {
            $r = (new ConsultaDniSimulada)->consultar($dni);
            $this->assertTrue($r->encontrado, $dni);
            $this->assertSame($caso['nombres'], $r->nombres, $dni);
            $this->assertSame($caso['apellido_paterno'], $r->apellidoPaterno, $dni);
            $this->assertSame($caso['ubicacion'] ?? null, $r->ubicacion, $dni);
        }
    }

    public function test_un_dni_fuera_de_los_casos_no_tiene_coincidencia(): void
    {
        foreach (['12345678', '00000000', '00000005', '99999999', '0000001'] as $dni) {
            $this->assertFalse((new ConsultaDniSimulada)->consultar($dni)->encontrado, $dni);
        }
    }

    public function test_el_dni_de_proveedor_caido_lanza_no_disponible_sin_el_dni_en_el_mensaje(): void
    {
        try {
            (new ConsultaDniSimulada)->consultar(ConsultaDniSimulada::DNI_NO_DISPONIBLE);
            $this->fail('Debía lanzar ProveedorNoDisponible.');
        } catch (ProveedorNoDisponible $e) {
            $this->assertStringNotContainsString(ConsultaDniSimulada::DNI_NO_DISPONIBLE, $e->getMessage());
        }
    }

    public function test_solo_esta_permitido_en_local_y_testing(): void
    {
        foreach (['local' => true, 'testing' => true, 'production' => false, 'staging' => false] as $entorno => $permitida) {
            $this->app['env'] = $entorno;
            $this->assertSame($permitida, ConsultaDniSimulada::permitida(), $entorno);
        }
    }

    public function test_fuera_de_local_y_testing_se_niega_a_responder(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(LogicException::class);
        (new ConsultaDniSimulada)->consultar('00000001');
    }
}
