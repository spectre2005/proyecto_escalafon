<?php

namespace Tests\Unit\Support\Identidad;

use App\Models\Ubigeo;
use App\Support\Identidad\UbicacionCatalogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UbicacionCatalogoTest extends TestCase
{
    use RefreshDatabase;

    private function ubicacion(string $departamento, string $provincia, string $distrito): array
    {
        return ['departamento' => $departamento, 'provincia' => $provincia, 'distrito' => $distrito];
    }

    public function test_normalizar_ignora_mayusculas_tildes_y_espacios_repetidos(): void
    {
        $this->assertSame('la convencion', UbicacionCatalogo::normalizar('  LA   CONVENCIÓN '));
        $this->assertSame('apurimac', UbicacionCatalogo::normalizar('Apurímac'));
        $this->assertSame('canete', UbicacionCatalogo::normalizar('CAÑETE'));
        $this->assertSame('', UbicacionCatalogo::normalizar('   '));
    }

    public function test_sin_ubicacion_del_proveedor_el_estado_es_sin_dato(): void
    {
        Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana']);

        $this->assertSame(['estado' => UbicacionCatalogo::SIN_DATO, 'ubicacion' => null], UbicacionCatalogo::emparejar(null));
    }

    public function test_una_coincidencia_unica_asigna_la_ubicacion_del_catalogo(): void
    {
        $lugar = Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana']);
        Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Echarati']);

        $r = UbicacionCatalogo::emparejar($this->ubicacion('CUSCO', 'LA CONVENCION', 'SANTA  ANA'));

        $this->assertSame(UbicacionCatalogo::ASIGNADA, $r['estado']);
        $this->assertSame(['id' => $lugar->id, 'region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana'], $r['ubicacion']);
    }

    public function test_exige_los_tres_niveles_y_no_aproxima(): void
    {
        Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana']);

        foreach ([
            $this->ubicacion('LIMA', 'LA CONVENCION', 'SANTA ANA'),
            $this->ubicacion('CUSCO', 'CUSCO', 'SANTA ANA'),
            $this->ubicacion('CUSCO', 'LA CONVENCION', 'SANTA'),
            $this->ubicacion('CUSCO', 'LA CONVENCION', ''),
        ] as $buscada) {
            $this->assertSame(['estado' => UbicacionCatalogo::SIN_COINCIDENCIA, 'ubicacion' => null], UbicacionCatalogo::emparejar($buscada), implode('/', $buscada));
        }
    }

    public function test_con_el_catalogo_vacio_no_hay_coincidencia(): void
    {
        $this->assertSame(UbicacionCatalogo::SIN_COINCIDENCIA, UbicacionCatalogo::emparejar($this->ubicacion('CUSCO', 'CUSCO', 'CUSCO'))['estado']);
    }

    public function test_varias_coincidencias_dejan_la_ubicacion_ambigua_sin_elegir_una(): void
    {
        Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana']);
        Ubigeo::factory()->create(['region' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA']);

        $this->assertSame(['estado' => UbicacionCatalogo::AMBIGUA, 'ubicacion' => null], UbicacionCatalogo::emparejar($this->ubicacion('Cusco', 'La Convención', 'Santa Ana')));
    }
}
