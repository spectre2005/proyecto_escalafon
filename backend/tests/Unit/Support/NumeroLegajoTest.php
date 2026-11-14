<?php

namespace Tests\Unit\Support;

use App\Models\Legajo;
use App\Models\Usuario;
use App\Support\NumeroLegajo;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Número de legajo correlativo por año, con el formato de config/legajo.php (LEG-{anio}-######).
 */
class NumeroLegajoTest extends TestCase
{
    use RefreshDatabase;

    private function legajo(string $numero): void
    {
        Legajo::factory()->create(['usuario_id' => Usuario::factory()->create()->id, 'numero_legajo' => $numero]);
    }

    public function test_el_primer_legajo_del_anio_empieza_en_uno_con_ceros_a_la_izquierda(): void
    {
        $this->assertSame('LEG-2026-000001', NumeroLegajo::siguiente(Carbon::create(2026, 3, 15)));
    }

    public function test_el_siguiente_es_el_mayor_correlativo_mas_uno_aunque_haya_huecos(): void
    {
        $this->legajo('LEG-2026-000003');
        $this->legajo('LEG-2026-000007');
        $this->legajo('LEG-2026-000001');

        $this->assertSame('LEG-2026-000008', NumeroLegajo::siguiente(Carbon::create(2026, 12, 31)));
    }

    public function test_el_correlativo_se_reinicia_cada_anio(): void
    {
        $this->legajo('LEG-2025-000042');

        $this->assertSame('LEG-2026-000001', NumeroLegajo::siguiente(Carbon::create(2026, 1, 1)));
        $this->assertSame('LEG-2025-000043', NumeroLegajo::siguiente(Carbon::create(2025, 6, 1)));
    }

    public function test_ignora_numeros_con_el_prefijo_pero_sin_correlativo_numerico(): void
    {
        $this->legajo('LEG-2026-ABC');
        $this->legajo('LEG-2026-12A');
        $this->legajo('LEG-2026-000002');

        $this->assertSame('LEG-2026-000003', NumeroLegajo::siguiente(Carbon::create(2026, 5, 5)));
    }

    public function test_ignora_numeros_con_otro_formato(): void
    {
        $this->legajo('OTRO-2026-000099');
        $this->legajo('2026-000050');

        $this->assertSame('LEG-2026-000001', NumeroLegajo::siguiente(Carbon::create(2026, 5, 5)));
    }

    public function test_sin_fecha_usa_el_anio_de_hoy(): void
    {
        $this->travelTo(Carbon::create(2027, 2, 10));

        $this->assertSame('LEG-2027-000001', NumeroLegajo::siguiente());
    }

    public function test_el_formato_sale_solo_de_la_configuracion(): void
    {
        config(['legajo.numero_legajo' => ['prefijo' => 'EXP/{anio}/', 'digitos' => 4]]);
        $this->legajo('EXP/2026/0009');

        $this->assertSame('EXP/2026/0010', NumeroLegajo::siguiente(Carbon::create(2026, 8, 1)));
    }

    public function test_un_correlativo_que_supera_los_digitos_no_se_trunca(): void
    {
        config(['legajo.numero_legajo' => ['prefijo' => 'L{anio}-', 'digitos' => 2]]);
        $this->legajo('L2026-99');

        $this->assertSame('L2026-100', NumeroLegajo::siguiente(Carbon::create(2026, 8, 1)));
    }
}
