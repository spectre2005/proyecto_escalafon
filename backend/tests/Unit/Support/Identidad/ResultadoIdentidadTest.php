<?php

namespace Tests\Unit\Support\Identidad;

use App\Support\Identidad\ResultadoIdentidad;
use Error;
use PHPUnit\Framework\TestCase;

class ResultadoIdentidadTest extends TestCase
{
    public function test_la_coincidencia_completa_conserva_todos_los_campos(): void
    {
        $ubicacion = ['departamento' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA'];
        $r = ResultadoIdentidad::coincidencia('Ana Lucía', 'Demostración', 'Ficticia', $ubicacion, 'JR. DEMOSTRACION 123');

        $this->assertTrue($r->encontrado);
        $this->assertSame('Ana Lucía', $r->nombres);
        $this->assertSame('Demostración', $r->apellidoPaterno);
        $this->assertSame('Ficticia', $r->apellidoMaterno);
        $this->assertSame($ubicacion, $r->ubicacion);
        $this->assertSame('JR. DEMOSTRACION 123', $r->direccion);
    }

    public function test_la_coincidencia_sin_datos_opcionales_los_deja_en_null(): void
    {
        $r = ResultadoIdentidad::coincidencia('María', 'Ejemplo', null);

        $this->assertTrue($r->encontrado);
        $this->assertNull($r->apellidoMaterno);
        $this->assertNull($r->ubicacion);
        $this->assertNull($r->direccion);
    }

    public function test_sin_coincidencia_no_trae_ningun_dato(): void
    {
        $r = ResultadoIdentidad::sinCoincidencia();

        $this->assertFalse($r->encontrado);
        $this->assertNull($r->nombres);
        $this->assertNull($r->apellidoPaterno);
        $this->assertNull($r->apellidoMaterno);
        $this->assertNull($r->ubicacion);
        $this->assertNull($r->direccion);
    }

    public function test_el_resultado_es_inmutable(): void
    {
        $r = ResultadoIdentidad::coincidencia('Ana', 'Prueba', null);

        $this->expectException(Error::class);
        $r->nombres = 'Otro';
    }
}
