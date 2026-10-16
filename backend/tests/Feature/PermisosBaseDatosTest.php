<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PermisosBaseDatosTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_denies_roles_without_explicit_policy_and_uses_public_slugs(): void
    {
        $usuario = $this->actuarComo();
        config(['permisos.fuente' => 'database']);
        $this->assertSame([], $usuario->fresh()->permisos());
        $this->artisan('permisos:importar-legacy')->assertSuccessful()->execute();
        $this->assertDatabaseCount('permisos_rol', 0);
        $this->artisan('permisos:importar-legacy', ['--aplicar' => true])->assertSuccessful()->execute();
        $this->assertContains('sistema.administrar', $usuario->fresh()->permisos());
        $this->assertNotContains('seccion07.ver_calificacion', $usuario->fresh()->permisos());
        $permiso = DB::table('permisos')->where('slug', 'sistema.administrar')->first();
        $this->assertIsInt((int) $permiso->id);
        DB::table('permisos_rol')->where('permiso_id', $permiso->id)->update(['permitido' => false]);
        $this->assertNotContains('sistema.administrar', $usuario->fresh()->permisos());
    }

    public function test_dual_mode_logs_drift_but_never_falls_back_to_legacy_grants(): void
    {
        $usuario = $this->actuarComo();
        config(['permisos.fuente' => 'dual']);
        Log::shouldReceive('notice')->once()->with('permisos.divergencia', \Mockery::on(fn ($context) => $context['usuario_id'] === $usuario->id));
        $this->assertSame([], $usuario->fresh()->permisos());
    }

    public function test_expired_inactive_and_unknown_policies_do_not_grant_permissions(): void
    {
        $usuario = $this->asignarRol(Usuario::factory()->create(), 'ADMIN', ['vigente_hasta' => today()->subDay()->toDateString()]);
        $this->artisan('permisos:importar-legacy', ['--aplicar' => true])->assertSuccessful()->execute();
        config(['permisos.fuente' => 'database']);
        $this->assertSame([], $usuario->fresh()->permisos());
        $activo = $this->asignarRol(Usuario::factory()->create());
        $this->assertContains('sistema.administrar', $activo->fresh()->permisos());
        $activo->update(['activo' => false]);
        $this->assertSame([], $activo->fresh()->permisos());
        config(['permisos.fuente' => 'modo-inexistente']);
        $this->assertSame([], $this->asignarRol(Usuario::factory()->create())->fresh()->permisos());
    }
}
