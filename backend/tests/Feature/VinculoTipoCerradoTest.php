<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\Legajo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vínculo cerrado (con fecha de cese) con el tipo Sin definir: solo se completa el tipo, solo si está vacío, una vez, y
 * queda en auditoria_eventos. Nada más del vínculo cerrado se corrige.
 */
class VinculoTipoCerradoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $persona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persona = Usuario::factory()->create(['tipo_personal' => 'administrativo']);
        Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'activo']);
    }

    private function url(VinculoLaboral $vinculo): string
    {
        return '/api/v1/expedientes/'.$this->persona->id.'/vinculos-laborales/'.$vinculo->id.'/completar-tipo';
    }

    private function vinculo(array $datos): VinculoLaboral
    {
        return VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'fecha_inicio' => '2020-01-01', ...$datos]);
    }

    public function test_a_closed_undefined_link_gets_its_type_once_and_it_is_audited(): void
    {
        $actor = $this->actuarComo();
        $vinculo = $this->vinculo(['regimen_laboral' => null, 'fecha_cese' => '2024-12-31', 'nombre_puesto' => 'Analista']);

        $this->postJson($this->url($vinculo), ['regimen_laboral' => 'carrera'])->assertOk()->assertJsonPath('data.regimen_laboral', 'carrera');
        $vinculo->refresh();
        $this->assertSame(['carrera', '2024-12-31', 'Analista'], [$vinculo->regimen_laboral, $vinculo->fecha_cese->format('Y-m-d'), $vinculo->nombre_puesto]);
        $evento = AuditoriaEvento::query()->where('entidad', 'vinculos_laborales')->where('entidad_id', $vinculo->id)->sole();
        $this->assertSame(['vinculo.completar_tipo', $actor->id, $this->persona->id], [$evento->accion, $evento->actor_usuario_id, $evento->usuario_titular_id]);
        $this->assertSame([['regimen_laboral' => null], ['regimen_laboral' => 'carrera']], [json_decode($evento->datos_antes, true), json_decode($evento->datos_despues, true)]);

        // Una vez puesto no se cambia, ni por aquí ni por «Corregir vínculo».
        $this->postJson($this->url($vinculo), ['regimen_laboral' => 'designado'])->assertConflict()->assertJsonPath('message', 'El tipo de vínculo ya está definido y no se cambia.');
        $this->patchJson('/api/v1/expedientes/'.$this->persona->id.'/vinculos-laborales/'.$vinculo->id, ['regimen_laboral' => 'designado'])->assertConflict();
        $this->assertSame('carrera', $vinculo->refresh()->regimen_laboral);
        $this->assertSame(1, AuditoriaEvento::query()->where('entidad_id', $vinculo->id)->count());
    }

    public function test_only_the_type_of_a_closed_undefined_link_can_be_completed(): void
    {
        $this->actuarComo();
        $cerrado = $this->vinculo(['regimen_laboral' => null, 'fecha_cese' => '2024-12-31', 'nombre_puesto' => 'Analista']);
        $this->postJson($this->url($cerrado), ['regimen_laboral' => 'carrera', 'nombre_puesto' => 'Otro'])->assertUnprocessable()->assertJsonValidationErrors('nombre_puesto');
        $this->postJson($this->url($cerrado), ['regimen_laboral' => '276'])->assertUnprocessable()->assertJsonValidationErrors('regimen_laboral');
        $this->postJson($this->url($cerrado), [])->assertUnprocessable()->assertJsonValidationErrors('regimen_laboral');
        $this->assertNull($cerrado->refresh()->regimen_laboral);

        $abierto = $this->vinculo(['regimen_laboral' => null, 'fecha_cese' => null]);
        $this->postJson($this->url($abierto), ['regimen_laboral' => 'carrera'])->assertConflict();
        $anulado = $this->vinculo(['regimen_laboral' => null, 'fecha_cese' => '2024-12-31', 'anulado' => true, 'motivo_anulacion' => 'Error']);
        $this->postJson($this->url($anulado), ['regimen_laboral' => 'carrera'])->assertConflict();
        $this->assertSame(0, AuditoriaEvento::query()->where('entidad', 'vinculos_laborales')->count());
    }

    public function test_it_needs_legajo_editar_and_an_open_legajo(): void
    {
        $vinculo = $this->vinculo(['regimen_laboral' => null, 'fecha_cese' => '2024-12-31']);
        config(['permisos.roles.solo_ver' => ['legajo.ver']]);
        $this->actuarComo(null, 'solo_ver');
        $this->postJson($this->url($vinculo), ['regimen_laboral' => 'carrera'])->assertForbidden();

        $this->actuarComo();
        Legajo::query()->where('usuario_id', $this->persona->id)->update(['estado_legajo' => 'pasivo']);
        $this->postJson($this->url($vinculo), ['regimen_laboral' => 'carrera'])->assertConflict();
        $this->assertNull($vinculo->refresh()->regimen_laboral);
    }
}
