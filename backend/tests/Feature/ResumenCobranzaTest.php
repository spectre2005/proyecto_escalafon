<?php

namespace Tests\Feature;

use App\Models\Plataforma\Institucion;
use App\Models\Plataforma\PeriodoServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/**
 * Resumen del tiempo de uso en el panel: por vencer (0 a 30 días), en gracia y vencidas según Institucion::servicio(),
 * e ingresos registrados del mes y del año por la fecha de registro en hora de Lima. «Hoy» es el 31/12/2026 a las
 * 20:00 en Lima, que en UTC ya es 01/01/2027: cruza a la vez el cambio de mes, de año y de zona horaria.
 */
class ResumenCobranzaTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const LIMA = 'America/Lima';

    protected function setUp(): void
    {
        parent::setUp();
        config(['plataforma.zona_horaria' => self::LIMA]);
        $this->travelTo(Carbon::parse('2026-12-31 20:00', self::LIMA));
        $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
    }

    private function con(string $slug, array $periodos = [], int $gracia = 0, string $estado = Institucion::ACTIVA): Institucion
    {
        $institucion = $this->institucion(['nombre' => ucfirst($slug), 'slug' => $slug, 'estado' => $estado, 'dias_gracia' => $gracia, 'aprovisionada_en' => now()]);
        foreach ($periodos as [$desde, $hasta, $anulado]) {
            $this->pago($institucion, $desde, $hasta, null, now(), $anulado);
        }

        return $institucion;
    }

    private function pago(Institucion $institucion, string $desde, string $hasta, ?float $monto, Carbon $registrado, bool $anulado = false): void
    {
        PeriodoServicio::query()->forceCreate(['institucion_id' => $institucion->id, 'desde' => $desde, 'hasta' => $hasta, 'monto' => $monto,
            'anulado' => $anulado, 'created_at' => $registrado->copy()->utc(), 'updated_at' => $registrado->copy()->utc()]);
    }

    private function resumen(): array
    {
        return $this->getJson('/api/plataforma/dashboard')->assertOk()->json('data');
    }

    public function test_due_dates_are_grouped_once_by_the_existing_service_state(): void
    {
        $this->con('vence-hoy', [['2026-01-01', '2026-12-31', false]]);
        $this->con('en-treinta', [['2026-02-01', '2027-01-30', false]]);
        $this->con('en-treintaiuno', [['2026-02-01', '2027-01-31', false]]);
        $this->con('encadenada', [['2026-01-01', '2026-12-31', false], ['2027-01-01', '2027-12-31', false]]);
        $this->con('en-gracia', [['2026-01-01', '2026-12-28', false]], gracia: 5);
        $this->con('suspendida', [['2026-01-01', '2026-12-30', false]], gracia: 10, estado: Institucion::SUSPENDIDA);
        $this->con('vencida', [['2026-01-01', '2026-12-01', false]]);
        $this->con('por-empezar', [['2027-02-01', '2028-01-31', false]]);
        $this->con('solo-anulado', [['2026-01-01', '2027-06-30', true]]);
        $this->con('sin-pagos');

        $v = $this->resumen()['vencimientos'];
        // Por vencer: de hoy a 30 días incluidos; la encadenada vence a fin de 2027.
        $this->assertSame([['vence-hoy', '2026-12-31', 0], ['en-treinta', '2027-01-30', 30]],
            array_map(fn (array $i): array => [Institucion::find($i['id'])->slug, $i['vence_el'], $i['dias_restantes']], $v['por_vencer']));
        // En gracia: venció el 28/12 con 5 días (hasta el 02/01, quedan 2); la suspendida también se informa, con su estado.
        $this->assertSame([['en-gracia', '2026-12-28', '2027-01-02', 2, 'activa'], ['suspendida', '2026-12-30', '2027-01-09', 9, 'suspendida']],
            array_map(fn (array $i): array => [Institucion::find($i['id'])->slug, $i['vence_el'], $i['gracia_hasta'], $i['dias_gracia_restantes'], $i['estado']], $v['en_gracia']));
        // Vencidas: la del 01/12 (30 días), la que solo tiene un pago futuro y la que solo tiene pagos anulados.
        $vencidas = collect($v['vencidas'])->keyBy(fn (array $i): string => Institucion::find($i['id'])->slug);
        $this->assertEqualsCanonicalizing(['vencida', 'por-empezar', 'solo-anulado'], $vencidas->keys()->all());
        $this->assertSame(['2026-12-01', 30, null], [$vencidas['vencida']['vence_el'], $vencidas['vencida']['dias_vencida'], $vencidas['vencida']['proximo_desde']]);
        $this->assertSame([null, null, '2027-02-01'], [$vencidas['por-empezar']['vence_el'], $vencidas['por-empezar']['dias_vencida'], $vencidas['por-empezar']['proximo_desde']]);
        // Sin ningún pago no se controla: solo se cuenta.
        $this->assertSame(1, $v['sin_control']);
        $this->assertSame(30, $v['dias']);

        // Ninguna institución en dos grupos.
        $ids = array_merge(...array_map(fn (string $g): array => array_column($v[$g], 'id'), ['por_vencer', 'en_gracia', 'vencidas']));
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_income_counts_registered_payments_by_local_month_and_year(): void
    {
        $uno = $this->con('uno');
        $lima = fn (string $fecha): Carbon => Carbon::parse($fecha, self::LIMA);
        $this->pago($uno, '2027-01-01', '2027-12-31', 100, $lima('2026-12-31 19:00'));  // en UTC ya es 01/01/2027
        $this->pago($uno, '2026-12-01', '2026-12-31', 50, $lima('2026-12-01 00:30'));
        $this->pago($uno, '2026-11-01', '2026-11-30', 25.5, $lima('2026-11-30 23:30'));  // en UTC ya es diciembre
        $this->pago($uno, '2026-01-01', '2026-01-31', 10, $lima('2026-01-01 00:10'));
        $this->pago($uno, '2025-12-01', '2025-12-31', 7, $lima('2025-12-31 23:50'));     // en UTC ya es 2026
        $this->pago($uno, '2028-01-01', '2028-01-31', 1000, $lima('2026-12-15 10:00'), anulado: true);
        $this->pago($uno, '2028-02-01', '2028-02-29', null, $lima('2026-12-20 10:00'));   // sin monto

        $i = $this->resumen()['ingresos'];
        $this->assertSame('S/', $i['moneda']);
        $this->assertSame(['total' => '150.00', 'pagos' => 3, 'sin_monto' => 1, 'desde' => '2026-12-01', 'hasta' => '2026-12-31'], $i['mes']);
        $this->assertSame(['total' => '185.50', 'pagos' => 5, 'sin_monto' => 1, 'desde' => '2026-01-01', 'hasta' => '2026-12-31'], $i['anio']);

        // Pasada la medianoche en Lima empieza enero de 2027: solo cuenta lo registrado desde entonces.
        $this->travelTo($lima('2027-01-01 00:30'));
        $this->pago($uno, '2028-03-01', '2028-03-31', 40, $lima('2027-01-01 00:15'));
        $i = $this->resumen()['ingresos'];
        $this->assertSame(['40.00', 1, '2027-01-01'], [$i['mes']['total'], $i['mes']['pagos'], $i['mes']['desde']]);
        $this->assertSame(['40.00', 1, 0], [$i['anio']['total'], $i['anio']['pagos'], $i['anio']['sin_monto']]);
    }

    public function test_without_payments_there_are_no_figures_or_due_dates(): void
    {
        $this->con('sin-pagos');

        $data = $this->resumen();
        $this->assertSame([[], [], [], 1], [$data['vencimientos']['por_vencer'], $data['vencimientos']['en_gracia'], $data['vencimientos']['vencidas'], $data['vencimientos']['sin_control']]);
        $this->assertSame(['0.00', 0, 0], [$data['ingresos']['mes']['total'], $data['ingresos']['mes']['pagos'], $data['ingresos']['mes']['sin_monto']]);
    }

    public function test_only_administrative_fields_are_exposed_and_only_to_the_platform_session(): void
    {
        $this->con('vencida', [['2026-01-01', '2026-12-01', false]]);
        $this->institucion(['slug' => 'otra', 'estado' => Institucion::ACTIVA, 'ruc' => '20100000009', 'aprovisionada_en' => now()]);

        $vencida = $this->resumen()['vencimientos']['vencidas'][0];
        $this->assertSame(['id', 'nombre', 'estado', 'vence_el', 'proximo_desde', 'dias_vencida'], array_keys($vencida));

        $this->postJson('/api/plataforma/auth/logout')->assertOk();
        $this->getJson('/api/plataforma/dashboard')->assertUnauthorized();
    }
}
