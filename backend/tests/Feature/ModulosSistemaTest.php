<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\EvaluacionDesempeno;
use App\Models\Legajo;
use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use App\Support\ModulosSistema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

class ModulosSistemaTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private function cambio(bool $activa = false, bool $actual = true): array
    {
        return ['activa' => $activa, 'activa_actual' => $actual, 'motivo' => 'Decisión de la administración institucional'];
    }

    public function test_only_authorized_administrators_can_configure_modules_and_public_state_contains_no_audit_details(): void
    {
        $this->getJson('/api/v1/configuracion/modulos-sistema')->assertUnauthorized();
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson('/api/v1/configuracion/modulos-sistema')->assertForbidden();
        $this->patchJson('/api/v1/configuracion/modulos-sistema/evaluaciones', $this->cambio())->assertForbidden();
        $this->getJson('/api/v1/funcionalidades')->assertOk()->assertJsonPath('data.modulos_sistema.evaluaciones', true)->assertJsonMissingPath('data.modulos_sistema.ultimo_cambio');
    }

    public function test_disabling_the_general_query_does_not_disable_section_seven_or_reports_or_delete_data(): void
    {
        $actor = $this->actuarComo();
        $legajo = Legajo::factory()->create();
        EvaluacionDesempeno::factory()->create(['usuario_id' => $legajo->usuario_id, 'ciclo_anio' => now()->year]);
        $this->getJson('/api/v1/seccion07/evaluaciones')->assertOk();
        $this->patchJson('/api/v1/configuracion/modulos-sistema/evaluaciones', $this->cambio())->assertOk()->assertJsonPath('data.activa', false);
        $estado = $this->getJson('/api/v1/funcionalidades')->assertOk()->assertJsonPath('data.modulos_sistema.evaluaciones', false);
        $this->assertTrue($estado->json('data.activas')['s07.evaluaciones']);
        $this->getJson('/api/v1/seccion07/evaluaciones')->assertForbidden()->assertJsonPath('codigo', 'modulo_desactivado');
        $this->getJson('/api/v1/seccion07-opciones')->assertForbidden();
        $this->getJson('/api/v1/expedientes/'.$legajo->usuario_id.'/seccion-07')->assertOk()->assertJsonCount(1, 'data.evaluaciones');
        $this->getJson('/api/v1/reportes/evaluaciones')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('evaluaciones_desempeno', 1);
        $evento = AuditoriaEvento::query()->where('entidad', 'modulos_sistema')->sole();
        $this->assertSame($actor->id, $evento->actor_usuario_id);
        $this->assertSame('modulo.desactivar', $evento->accion);
        $this->patchJson('/api/v1/configuracion/modulos-sistema/evaluaciones', $this->cambio(true, false))->assertOk()->assertJsonPath('data.activa', true);
        $this->getJson('/api/v1/seccion07/evaluaciones')->assertOk();
    }

    public function test_stale_updates_unknown_options_and_invalid_reasons_are_rejected(): void
    {
        $this->actuarComo();
        $url = '/api/v1/configuracion/modulos-sistema/evaluaciones';
        $this->patchJson($url, ['activa' => false, 'activa_actual' => true, 'motivo' => '     '])->assertUnprocessable();
        $this->patchJson($url, ['activa' => false, 'activa_actual' => true, 'motivo' => ['invalido']])->assertUnprocessable();
        $this->patchJson($url, [...$this->cambio(), 'institucion_id' => 'otra'])->assertUnprocessable();
        $this->patchJson('/api/v1/configuracion/modulos-sistema/inexistente', $this->cambio())->assertNotFound();
        $this->patchJson($url, $this->cambio())->assertOk();
        $this->patchJson($url, $this->cambio())->assertConflict();
        $this->assertSame(1, AuditoriaEvento::query()->where('entidad', 'modulos_sistema')->count());
    }

    public function test_an_institution_changes_only_its_own_modules_and_the_state_is_shared_with_the_platform(): void
    {
        $actor = $this->actuarComo();
        $uno = $this->institucion(['slug' => 'uno']);
        $dos = $this->institucion(['slug' => 'dos']);
        app()->instance(Institucion::class, $uno);
        try {
            $this->patchJson('/api/v1/configuracion/modulos-sistema/evaluaciones', $this->cambio())->assertOk()->assertJsonPath('data.activa', false);
            $this->assertFalse(ModulosSistema::estados($uno->refresh())[0]['activa']);
            $this->assertTrue(ModulosSistema::estados($dos->refresh())[0]['activa']);
            $evento = AuditoriaPlataforma::query()->where('accion', 'modulo.desactivado')->sole();
            $this->assertSame($uno->id, $evento->institucion_id);
            $this->assertSame($actor->id, $evento->datos_despues['actor_id']);
            $this->assertSame('institucion', $evento->datos_despues['origen']);
            $this->getJson('/api/plataforma/instituciones/'.$dos->id.'/modulos-sistema')->assertUnauthorized();
            app()->instance(Institucion::class, $dos);
            $this->getJson('/api/v1/funcionalidades')->assertOk()->assertJsonPath('data.modulos_sistema.evaluaciones', true);
        } finally {
            app()->forgetInstance(Institucion::class);
        }
    }

    public function test_platform_administrator_can_toggle_each_institution_and_creates_an_audit_record(): void
    {
        $admin = $this->administrador();
        $uno = $this->institucion();
        $dos = $this->institucion();
        $this->postJson('/api/plataforma/auth/login', ['email' => $admin->email, 'password' => 'Clave-segura-2026'])->assertOk();
        $url = '/api/plataforma/instituciones/'.$uno->id.'/modulos-sistema';
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.activa', true);
        $this->patchJson($url.'/evaluaciones', $this->cambio())->assertOk()->assertJsonPath('data.activa', false)->assertJsonPath('data.ultimo_cambio.origen', 'plataforma');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.activa', false);
        $this->getJson('/api/plataforma/instituciones/'.$dos->id.'/modulos-sistema')->assertOk()->assertJsonPath('data.0.activa', true);
        $evento = AuditoriaPlataforma::query()->where('accion', 'modulo.desactivado')->sole();
        $this->assertSame($admin->id, $evento->administrador_id);
        $this->patchJson($url.'/evaluaciones', $this->cambio(true, false))->assertOk()->assertJsonPath('data.activa', true);
    }

    public function test_existing_institutions_start_with_every_module_active_and_each_one_can_change_it(): void
    {
        $migracion = require database_path('migrations/plataforma/2026_10_18_044507_add_modulos_sistema_to_instituciones_table.php');
        $migracion->down();
        $uno = $this->institucion(['slug' => 'uno']);
        $dos = $this->institucion(['slug' => 'dos']);
        $migracion->up();
        $this->assertTrue(ModulosSistema::estados($uno->refresh())[0]['activa']);
        $this->assertTrue(ModulosSistema::estados($dos->refresh())[0]['activa']);
        $this->assertNull($uno->modulos_sistema);
        $this->actuarComo();
        app()->instance(Institucion::class, $uno);
        try {
            $this->patchJson('/api/v1/configuracion/modulos-sistema/evaluaciones', $this->cambio())->assertOk()->assertJsonPath('data.activa', false);
            $this->getJson('/api/v1/funcionalidades')->assertOk()->assertJsonPath('data.modulos_sistema.evaluaciones', false);
            $this->assertTrue(ModulosSistema::estados($dos->refresh())[0]['activa']);
        } finally {
            app()->forgetInstance(Institucion::class);
        }
    }
}
