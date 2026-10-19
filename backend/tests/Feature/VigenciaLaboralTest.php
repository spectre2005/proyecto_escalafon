<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use App\Support\AperturaLegajo;
use App\Support\CierreLegajo;
use App\Support\VigenciaLaboral;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VigenciaLaboralTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_and_model_use_inclusive_effective_dates_and_latest_valid_addendum(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 02:00:00', 'UTC'));
        $this->assertSame('2026-10-17', VigenciaLaboral::hoy());
        $casos = [
            [['fecha_inicio' => '2026-10-17', 'fecha_fin_prevista' => '2026-10-17'], true],
            [['fecha_inicio' => '2026-10-18'], false],
            [['fecha_inicio' => '2026-01-01', 'fecha_fin_prevista' => '2026-10-16'], false],
            [['fecha_inicio' => '2026-01-01', 'fecha_cese' => '2026-10-17'], true],
            [['fecha_inicio' => '2026-01-01', 'fecha_cese' => '2026-10-16'], false],
            [['fecha_inicio' => '2026-01-01', 'anulado' => true], false],
        ];
        foreach ($casos as [$datos, $esperado]) {
            $vinculo = VinculoLaboral::factory()->create($datos);
            $this->assertSame($esperado, VigenciaLaboral::vigente($vinculo));
            $this->assertSame($esperado, VinculoLaboral::query()->vigente()->whereKey($vinculo->id)->exists());
        }
        $vinculo = VinculoLaboral::factory()->create(['fecha_inicio' => '2026-01-01', 'fecha_fin_prevista' => '2026-09-30']);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31', 'anulado' => false]);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2026-10-15', 'fecha_fin' => '2026-10-16', 'anulado' => true]);
        $this->assertTrue(VigenciaLaboral::vigente($vinculo->refresh()));
        $this->assertTrue(VinculoLaboral::query()->vigente()->whereKey($vinculo->id)->exists());
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2026-10-16', 'fecha_fin' => '2026-10-16', 'anulado' => false]);
        $this->assertFalse(VigenciaLaboral::vigente($vinculo->refresh()));
        $this->assertFalse(VinculoLaboral::query()->vigente()->whereKey($vinculo->id)->exists());
    }

    public function test_closure_waits_for_last_link_and_allows_reentry_without_losing_documents(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 12:00:00', 'America/Lima'));
        $persona = Usuario::factory()->create();
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'activo', 'fecha_apertura' => '2026-01-01']);
        $primero = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2026-01-01', 'fecha_cese' => '2026-10-16']);
        $ultimo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2026-01-01', 'fecha_cese' => '2026-10-17']);
        $this->assertFalse(CierreLegajo::actualizar($persona->id));
        $this->travel(1)->days();
        $this->assertTrue(CierreLegajo::actualizar($persona->id));
        $this->assertSame('pasivo', $legajo->refresh()->estado_legajo);
        $this->assertFalse(CierreLegajo::actualizar($persona->id));
        $this->assertDatabaseCount('vinculos_laborales', 2);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $legajo->id, 'accion' => 'legajo.pase_pasivo_automatico']);
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $nuevo = AperturaLegajo::abrir($persona->id);
        $this->assertNotSame($legajo->id, $nuevo->id);
        $this->assertFalse(CierreLegajo::actualizar($persona->id));
        $this->assertSame(2, $persona->legajos()->count());
    }

    public function test_future_link_does_not_keep_old_legajo_active_after_last_effective_end(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 12:00:00', 'America/Lima'));
        $persona = Usuario::factory()->create();
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'activo']);
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2026-01-01', 'fecha_cese' => '2026-10-16']);
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2026-11-01']);
        $this->assertTrue(CierreLegajo::actualizar($persona->id));
        $this->assertSame('pasivo', $legajo->refresh()->estado_legajo);
        $soloFuturo = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $soloFuturo->id, 'estado_legajo' => 'activo']);
        VinculoLaboral::factory()->create(['usuario_id' => $soloFuturo->id, 'fecha_inicio' => '2026-11-01']);
        $this->assertFalse(CierreLegajo::actualizar($soloFuturo->id));
    }
}
