<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Los listados de personal separan el acceso al sistema (activo, la cuenta) de la situación laboral. */
class SituacionLaboralTest extends TestCase
{
    use RefreshDatabase;

    private function situaciones(): array
    {
        return collect($this->getJson('/api/v1/usuarios')->assertOk()->json('data'))->mapWithKeys(fn (array $fila): array => [$fila['id'] => [$fila['activo'], $fila['situacion']]])->all();
    }

    public function test_staff_with_a_current_link_is_not_shown_as_inactive_because_it_has_no_account(): void
    {
        $admin = $this->actuarComo();
        $conVinculo = Usuario::factory()->create(['activo' => false]);
        Legajo::factory()->create(['usuario_id' => $conVinculo->id, 'estado_legajo' => 'activo']);
        VinculoLaboral::factory()->create(['usuario_id' => $conVinculo->id, 'fecha_cese' => null, 'anulado' => false]);

        $sinVinculo = Usuario::factory()->create(['activo' => false]);
        Legajo::factory()->create(['usuario_id' => $sinVinculo->id, 'estado_legajo' => 'activo']);
        // Cesados y anulados no son vigentes.
        VinculoLaboral::factory()->create(['usuario_id' => $sinVinculo->id, 'fecha_cese' => '2024-12-31', 'anulado' => false]);
        VinculoLaboral::factory()->create(['usuario_id' => $sinVinculo->id, 'fecha_cese' => null, 'anulado' => true]);

        $sinLegajo = Usuario::factory()->create(['activo' => true]);
        Legajo::factory()->create(['usuario_id' => $sinLegajo->id, 'estado_legajo' => 'pasivo']);

        $situaciones = $this->situaciones();
        $this->assertSame([false, 'vinculo_vigente'], $situaciones[$conVinculo->id]);
        $this->assertSame([false, 'sin_vinculo_vigente'], $situaciones[$sinVinculo->id]);
        $this->assertSame([true, 'sin_legajo'], $situaciones[$sinLegajo->id]);
        $this->assertSame('sin_legajo', $situaciones[$admin->id][1]);
        $this->getJson('/api/v1/usuarios/'.$conVinculo->id)->assertOk()->assertJsonPath('data.situacion', 'vinculo_vigente')->assertJsonPath('data.activo', false);

        // Mostrar la situación no cambia la cuenta.
        $this->assertDatabaseHas('usuarios', ['id' => $conVinculo->id, 'activo' => false]);
        $this->assertDatabaseHas('usuarios', ['id' => $sinLegajo->id, 'activo' => true]);
    }
}
