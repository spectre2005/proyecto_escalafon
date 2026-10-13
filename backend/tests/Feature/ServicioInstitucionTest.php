<?php

namespace Tests\Feature;

use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use Database\Seeders\AdministradorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/**
 * Tiempo de uso pagado: estado del servicio (vigente, por vencer, en gracia, vencido) con fechas en hora local, registro
 * y anulación de periodos desde el panel, bloqueo de la institución vencida por subdominio y aviso a sus
 * administradores.
 */
class ServicioInstitucionTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const ADMIN = 'admin@uno.test';

    private const CLAVE = 'Clave-inicial-1';

    /** @var list<string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['plataforma.zona_horaria' => 'America/Lima', 'plataforma.aviso_vencimiento_dias' => 15]);
        // 15/06/2026 a mediodía en Lima (17:00 UTC).
        $this->travelTo(Carbon::parse('2026-06-15 12:00', 'America/Lima'));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $archivo) {
            File::delete($archivo);
        }
        parent::tearDown();
    }

    /** @param list<array{0: string, 1: string, 2?: bool}> $periodos [desde, hasta, anulado] */
    private function con(array $periodos, int $gracia = 0, array $datos = []): Institucion
    {
        $institucion = $this->institucion(['estado' => Institucion::ACTIVA, 'aprovisionada_en' => now(), 'dias_gracia' => $gracia, ...$datos]);
        foreach ($periodos as $p) {
            $institucion->periodos()->create(['desde' => $p[0], 'hasta' => $p[1], 'anulado' => $p[2] ?? false]);
        }

        return $institucion->fresh();
    }

    public function test_the_service_state_follows_the_paid_periods(): void
    {
        $this->assertSame('sin_periodos', $this->con([])->servicio()['estado']);

        $vigente = $this->con([['2026-01-01', '2026-06-30']])->servicio();
        $this->assertSame(['vigente', '2026-06-30', 15], [$vigente['estado'], $vigente['vence_el'], $vigente['dias_restantes']]);

        // Periodos consecutivos se encadenan; uno anulado no cuenta.
        $encadenado = $this->con([['2026-01-01', '2026-06-30'], ['2026-07-01', '2026-12-31'], ['2027-01-01', '2027-12-31', true]])->servicio();
        $this->assertSame(['vigente', '2026-12-31'], [$encadenado['estado'], $encadenado['vence_el']]);

        $gracia = $this->con([['2026-01-01', '2026-06-10']], gracia: 7)->servicio();
        $this->assertSame(['en_gracia', '2026-06-10', '2026-06-17'], [$gracia['estado'], $gracia['vence_el'], $gracia['gracia_hasta']]);

        $this->assertSame('vencido', $this->con([['2026-01-01', '2026-06-10']], gracia: 3)->servicio()['estado']);
        // Un pago que aún no empieza no cubre hoy.
        $futuro = $this->con([['2026-07-01', '2026-12-31']])->servicio();
        $this->assertSame(['vencido', '2026-07-01'], [$futuro['estado'], $futuro['proximo_desde']]);
    }

    public function test_the_last_paid_day_lasts_until_midnight_local_time(): void
    {
        $institucion = $this->con([['2026-01-01', '2026-06-15']]);

        // 23:30 en Lima ya es 16/06 en UTC: sigue vigente.
        $this->travelTo(Carbon::parse('2026-06-15 23:30', 'America/Lima'));
        $this->assertSame('vigente', $institucion->servicio()['estado']);
        $this->travelTo(Carbon::parse('2026-06-16 00:01', 'America/Lima'));
        $this->assertSame('vencido', $institucion->servicio()['estado']);
        $this->assertFalse($institucion->operativa());
    }

    public function test_payments_are_registered_without_overlaps_and_annulled_with_a_reason(): void
    {
        $institucion = $this->con([]);
        $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
        $url = "/api/plataforma/instituciones/{$institucion->id}/periodos";

        $primero = $this->postJson($url, ['desde' => '2026-01-01', 'hasta' => '2026-12-31', 'monto' => 1200.5, 'comprobante' => ' F001-123 '])->assertCreated()
            ->assertJsonPath('data.comprobante', 'F001-123')->assertJsonPath('data.registrado_por', 'Superadministración')->json('data.id');
        $this->postJson($url, ['desde' => '2026-12-01', 'hasta' => '2027-03-31'])->assertUnprocessable()->assertJsonValidationErrors('desde');
        $this->postJson($url, ['desde' => '2027-01-01', 'hasta' => '2026-12-31'])->assertUnprocessable()->assertJsonValidationErrors('hasta');
        $this->postJson($url, ['desde' => '2027-01-01', 'hasta' => '2027-12-31', 'activo' => true])->assertUnprocessable()->assertJsonValidationErrors('activo');

        $this->getJson($url)->assertOk()->assertJsonPath('data.hoy', '2026-06-15')->assertJsonPath('data.servicio.estado', 'vigente')->assertJsonPath('data.servicio.vence_el', '2026-12-31');
        $this->postJson("{$url}/{$primero}/anular", ['motivo' => 'x'])->assertUnprocessable();
        $this->postJson("{$url}/{$primero}/anular", ['motivo' => 'Monto equivocado'])->assertOk()->assertJsonPath('data.anulado', true);
        $this->postJson("{$url}/{$primero}/anular", ['motivo' => 'Monto equivocado'])->assertStatus(409);
        // Anular el único pago no la deja sin control: queda vencida, no «sin periodos».
        $this->getJson($url)->assertJsonPath('data.servicio.estado', 'vencido')->assertJsonCount(1, 'data.periodos');
        // Anulado, su rango queda libre para el periodo correcto.
        $this->postJson($url, ['desde' => '2026-01-01', 'hasta' => '2026-12-31', 'monto' => 1000])->assertCreated();

        $this->patchJson("/api/plataforma/instituciones/{$institucion->id}", ['dias_gracia' => 10])->assertOk()->assertJsonPath('data.dias_gracia', 10);
        $this->patchJson("/api/plataforma/instituciones/{$institucion->id}", ['dias_gracia' => 91])->assertUnprocessable();
        $this->assertSame(['periodo.registrado', 'periodo.anulado', 'periodo.registrado', 'institucion.editada'],
            AuditoriaPlataforma::query()->where('institucion_id', $institucion->id)->orderBy('created_at')->orderBy('id')->pluck('accion')->all());
    }

    /** Institución por subdominio con una base SQLite migrada y su administrador. */
    private function porSubdominio(array $periodos, int $gracia = 0): Institucion
    {
        $this->temporales[] = $base = tempnam(sys_get_temp_dir(), 'inst_');
        config(['plataforma.modo' => 'subdominio', 'sanctum.stateful' => ['*.localhost:5173'],
            'auth.administrador_inicial' => ['email' => self::ADMIN, 'password' => self::CLAVE],
            'database.connections.preparar' => ['driver' => 'sqlite', 'database' => $base, 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->artisan('migrate', ['--database' => 'preparar', '--force' => true])->assertSuccessful();
        $this->artisan('db:seed', ['--class' => AdministradorSeeder::class, '--database' => 'preparar', '--force' => true])->assertSuccessful();
        DB::purge('preparar');

        return $this->con($periodos, $gracia, ['slug' => 'uno', 'base_datos' => $base, 'almacenamiento' => 'instituciones/uno']);
    }

    private function login()
    {
        return $this->withHeader('Origin', 'http://uno.localhost:5173')
            ->postJson('http://uno.localhost/api/v1/auth/login', ['email' => self::ADMIN, 'password' => self::CLAVE]);
    }

    public function test_an_expired_institution_is_blocked_and_its_administrators_are_warned_before(): void
    {
        $this->porSubdominio([['2026-01-01', '2026-06-25']]);
        $this->login()->assertOk()->assertJsonPath('data.aviso_servicio.tipo', 'por_vencer')
            ->assertJsonPath('data.aviso_servicio.vence_el', '2026-06-25')->assertJsonPath('data.aviso_servicio.dias_restantes', 10);

        $this->travelTo(Carbon::parse('2026-06-26 08:00', 'America/Lima'));
        $this->login()->assertForbidden()->assertJsonPath('codigo', 'institucion_vencida')
            ->assertJsonPath('message', 'El tiempo de uso de esta institución venció el 25/06/2026. Comunícate con el soporte de la plataforma para renovarlo.');
    }

    public function test_during_the_grace_days_it_works_with_a_warning(): void
    {
        $this->porSubdominio([['2026-01-01', '2026-06-10']], gracia: 7);

        $this->login()->assertOk()->assertJsonPath('data.aviso_servicio.tipo', 'en_gracia')->assertJsonPath('data.aviso_servicio.gracia_hasta', '2026-06-17');
    }

    public function test_far_from_expiring_or_without_periods_there_is_no_warning(): void
    {
        $this->porSubdominio([['2026-01-01', '2026-12-31']]);

        $this->login()->assertOk()->assertJsonPath('data.aviso_servicio', null);
    }
}
