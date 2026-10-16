<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «Completa el perfil»: la ficha lista las condiciones Sin responder y se responden con los endpoints de siempre (las de
 * la persona en la ficha; las del puesto en su vínculo). El Inicio lista quiénes tienen pendientes. Sin responder sigue
 * siendo válido y nunca se convierte en No.
 */
class PerfilPendienteTest extends TestCase
{
    use RefreshDatabase;

    private const PUESTO = ['obligado_djbr', 'exige_colegiatura', 'exige_estudios_basicos', 'tiene_periodo_prueba'];

    private Usuario $persona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persona = Usuario::factory()->create(['tiene_derechohabientes' => null, 'con_discapacidad' => null]);
        Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'activo']);
    }

    private function vinculo(array $banderas = [], array $datos = []): VinculoLaboral
    {
        return VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'fecha_cese' => null, 'anulado' => false,
            ...array_fill_keys(self::PUESTO, null), ...$banderas, ...$datos]);
    }

    private function pendientes(): array
    {
        return $this->getJson('/api/v1/expedientes/'.$this->persona->id)->assertOk()->json('data.condiciones.pendientes');
    }

    public function test_the_file_lists_only_unanswered_conditions_where_they_are_stored(): void
    {
        $this->actuarComo();
        $vinculo = $this->vinculo(['obligado_djbr' => true, 'exige_colegiatura' => false], ['nombre_puesto' => 'Especialista']);
        // Un Sí en otro vínculo vigente resuelve la condición (como en AplicacionDocumental); No solo si todos dicen No; uno
        // cesado no cuenta.
        $otro = $this->vinculo(['exige_estudios_basicos' => true, 'exige_colegiatura' => null]);
        $this->vinculo(datos: ['fecha_cese' => '2024-12-31']);

        $delPrimero = array_values(array_filter($this->pendientes(), fn (array $p): bool => ($p['vinculo_id'] ?? $vinculo->id) === $vinculo->id));
        $this->assertSame([
            ['bandera' => 'tiene_derechohabientes', 'ambito' => 'persona'],
            ['bandera' => 'con_discapacidad', 'ambito' => 'persona'],
            ['bandera' => 'tiene_periodo_prueba', 'ambito' => 'vinculo', 'vinculo_id' => $vinculo->id, 'puesto' => 'Especialista'],
        ], $delPrimero);
        // La colegiatura: No en uno y sin responder en el otro sigue pendiente, en el vínculo que la tiene sin responder.
        $this->assertContains(['bandera' => 'exige_colegiatura', 'ambito' => 'vinculo', 'vinculo_id' => $otro->id, 'puesto' => $otro->nombre_puesto], $this->pendientes());
        $otro->update(['exige_colegiatura' => false]);
        $this->assertNotContains('exige_colegiatura', array_column($this->pendientes(), 'bandera'));
        $this->assertCount(4, $this->pendientes(), 'El período de prueba falta en los dos vínculos vigentes.');
    }

    public function test_without_a_current_link_job_conditions_are_not_asked_in_the_file(): void
    {
        $this->actuarComo();
        $this->assertSame(['tiene_derechohabientes', 'con_discapacidad'], array_column($this->pendientes(), 'bandera'));
        $this->persona->update(['tiene_derechohabientes' => false, 'con_discapacidad' => true]);
        $this->assertSame([], $this->pendientes());
    }

    public function test_each_of_the_six_conditions_is_saved_in_its_own_record_and_leaves_the_block(): void
    {
        $this->actuarComo();
        $vinculo = $this->vinculo();
        $url = '/api/v1/expedientes/'.$this->persona->id;
        $this->assertCount(6, $this->pendientes());

        $this->patchJson($url, ['tiene_derechohabientes' => true])->assertOk();
        $this->patchJson($url, ['con_discapacidad' => false])->assertOk();
        $respuestas = ['obligado_djbr' => true, 'exige_colegiatura' => false, 'exige_estudios_basicos' => true, 'tiene_periodo_prueba' => false];
        foreach ($respuestas as $bandera => $valor) {
            $this->patchJson($url.'/vinculos-laborales/'.$vinculo->id, [$bandera => $valor])->assertOk();
            // Las demás siguen Sin responder: responder una no convierte las otras en No.
            $this->assertNotContains($bandera, array_column($this->pendientes(), 'bandera'));
        }

        $this->assertSame([], $this->pendientes());
        $this->assertDatabaseHas('usuarios', ['id' => $this->persona->id, 'tiene_derechohabientes' => true, 'con_discapacidad' => false]);
        $this->assertDatabaseHas('vinculos_laborales', ['id' => $vinculo->id, ...$respuestas]);
        // Las del puesto se guardan en el vínculo, no en la copia de la persona.
        $this->assertDatabaseHas('usuarios', ['id' => $this->persona->id, ...array_fill_keys(self::PUESTO, null)]);
        // Sin responder sigue siendo válido.
        $this->patchJson($url.'/vinculos-laborales/'.$vinculo->id, ['tiene_periodo_prueba' => null])->assertOk();
        $this->assertSame(['tiene_periodo_prueba'], array_column($this->pendientes(), 'bandera'));
    }

    public function test_sensitive_permissions_still_apply(): void
    {
        // Sin datos sensibles no se informan ni se guardan las condiciones de la persona.
        $this->actuarComo(null, 'asistente_legajo');
        $vinculo = $this->vinculo();
        $this->assertSame(self::PUESTO, array_column($this->pendientes(), 'bandera'));
        $this->patchJson('/api/v1/expedientes/'.$this->persona->id, ['tiene_derechohabientes' => true])->assertForbidden();

        // Con datos sensibles pero sin discapacidad.editar, la discapacidad no se responde.
        config()->set('permisos.roles.ficha_sin_discapacidad', ['legajo.ver', 'legajo.editar']);
        $editor = $this->asignarRol(Usuario::factory()->create(), 'ficha_sin_discapacidad', rol: ['ve_datos_sensibles' => true]);
        Sanctum::actingAs($editor->refresh());
        $this->patchJson('/api/v1/expedientes/'.$this->persona->id, ['con_discapacidad' => true])->assertForbidden();
        $this->patchJson('/api/v1/expedientes/'.$this->persona->id, ['tiene_derechohabientes' => true])->assertOk();
        $this->assertDatabaseHas('usuarios', ['id' => $this->persona->id, 'con_discapacidad' => null, 'tiene_derechohabientes' => true]);
        $this->assertDatabaseHas('vinculos_laborales', ['id' => $vinculo->id, 'obligado_djbr' => null]);
    }

    public function test_home_lists_who_has_pending_conditions_and_not_who_has_none(): void
    {
        $admin = $this->actuarComo();
        $admin->update(['tiene_derechohabientes' => false, 'con_discapacidad' => false]);
        $this->vinculo(['obligado_djbr' => true, 'exige_colegiatura' => false, 'exige_estudios_basicos' => false]);
        $completa = Usuario::factory()->create(['tiene_derechohabientes' => true, 'con_discapacidad' => false]);
        VinculoLaboral::factory()->create(['usuario_id' => $completa->id, 'fecha_cese' => null, 'anulado' => false,
            'obligado_djbr' => false, 'exige_colegiatura' => false, 'exige_estudios_basicos' => true, 'tiene_periodo_prueba' => false]);

        $datos = $this->getJson('/api/v1/personal/banderas-sin-responder')->assertOk()->json('data');
        $this->assertSame(1, $datos['total']);
        $this->assertSame(1, $datos['personas_mostradas']);
        $this->assertSame($this->persona->id, $datos['personas'][0]['id']);
        $this->assertSame(['tiene_derechohabientes', 'con_discapacidad', 'tiene_periodo_prueba'], $datos['personas'][0]['pendientes']);
        $this->assertNotContains($completa->id, array_column($datos['personas'], 'id'));
        $this->assertNotContains($admin->id, array_column($datos['personas'], 'id'));
    }
}
