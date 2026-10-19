<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccesoPersonalTest extends TestCase
{
    use RefreshDatabase;

    private function datos(): array
    {
        return ['vigente_desde' => today('America/Lima')->toDateString(), 'vigente_hasta' => today('America/Lima')->addMonth()->toDateString(), 'motivo' => 'Apoyo de archivo'];
    }

    public function test_grant_creates_only_the_fixed_role_issues_a_temporary_password_and_can_be_revoked(): void
    {
        $actor = $this->actuarComo();
        $persona = Usuario::factory()->create(['email_institucional' => 'apoyo@example.test', 'activo' => true]);
        $response = $this->postJson('/api/v1/accesos-personal/'.$persona->id, $this->datos())->assertCreated();
        $clave = $response->json('clave_temporal');
        $this->assertTrue(Hash::check($clave, $persona->fresh()->password));
        $this->assertTrue($persona->fresh()->debe_cambiar_password);
        $this->assertTrue($persona->fresh()->tienePermiso('documento.recepcionar'));
        foreach (['datos_sensibles.ver', 'sistema.administrar', 'documento.revisar', 'usuarios.gestionar'] as $permiso) {
            $this->assertFalse($persona->fresh()->tienePermiso($permiso));
        }
        $this->postJson('/api/v1/accesos-personal/'.$persona->id, $this->datos())->assertConflict();
        $id = $response->json('data.id');
        $this->postJson('/api/v1/accesos-personal/'.$persona->id.'/'.$id.'/revocar', ['motivo' => 'Fin del apoyo'])->assertOk();
        $this->assertSame([], $persona->fresh()->permisos());
        $eventos = AuditoriaEvento::query()->where('entidad_id', $id)->orderBy('id')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame($actor->id, $eventos[0]->actor_usuario_id);
        $this->assertStringNotContainsString($clave, $eventos->toJson());
        $this->assertSame($eventos[0]->hash_registro, $eventos[1]->hash_previo);
        $this->assertDatabaseHas('usuario_roles', ['id' => $id]);
    }

    public function test_assistants_cannot_grant_revoke_or_reset_access_and_cannot_escalate_privileges(): void
    {
        $actor = $this->actuarComo(null, 'asistente_legajo');
        $this->getJson('/api/v1/accesos-personal')->assertForbidden();
        $this->postJson('/api/v1/accesos-personal/'.$actor->id, $this->datos())->assertForbidden();
        $this->postJson('/api/v1/accesos-personal/'.$actor->id.'/restablecer', ['motivo' => 'Intento'])->assertForbidden();
        $this->postJson('/api/v1/usuario-roles', ['usuario_id' => $actor->id])->assertForbidden();
    }

    public function test_admin_accounts_and_forged_roles_are_rejected(): void
    {
        $admin = $this->actuarComo();
        $this->postJson('/api/v1/accesos-personal/'.$admin->id, $this->datos())->assertConflict();
        $persona = Usuario::factory()->create(['email_institucional' => 'apoyo@example.test']);
        $this->postJson('/api/v1/accesos-personal/'.$persona->id, [...$this->datos(), 'rol_id' => 'ADMIN'])->assertUnprocessable()->assertJsonValidationErrors('rol_id');
        $this->postJson('/api/v1/accesos-personal/'.$persona->id, [...$this->datos(), 'vigente_hasta' => today('America/Lima')->subDay()->toDateString()])->assertUnprocessable();
        $persona->update(['email_institucional' => 'correo-invalido']);
        $this->postJson('/api/v1/accesos-personal/'.$persona->id, $this->datos())->assertUnprocessable();
    }

    public function test_reset_is_audited_but_the_secret_is_returned_only_once(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['email_institucional' => 'apoyo@example.test', 'activo' => true]);
        $this->postJson('/api/v1/accesos-personal/'.$persona->id, $this->datos())->assertCreated();
        $response = $this->postJson('/api/v1/accesos-personal/'.$persona->id.'/restablecer', ['motivo' => 'Olvido de contraseña'])->assertOk();
        $clave = $response->json('clave_temporal');
        $this->assertTrue(Hash::check($clave, $persona->fresh()->password));
        $lista = $this->getJson('/api/v1/accesos-personal?q=apoyo')->assertOk();
        $this->assertStringNotContainsString($clave, $lista->getContent());
        $this->assertStringNotContainsString($clave, AuditoriaEvento::query()->get()->toJson());
        $asignacion = UsuarioRol::query()->where('usuario_id', $persona->id)->firstOrFail();
        $otro = Usuario::factory()->create();
        $this->postJson('/api/v1/accesos-personal/'.$otro->id.'/'.$asignacion->id.'/revocar', ['motivo' => 'Asignación ajena'])->assertNotFound();
        Sanctum::actingAs($persona->fresh());
        $this->getJson('/api/v1/usuarios')->assertForbidden();
    }

    public function test_first_grant_activates_an_inactive_account_on_the_lima_date_and_audits_without_secrets(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 20:30:00', 'America/Lima'));
        $actor = $this->actuarComo();
        $persona = Usuario::factory()->create(['email_institucional' => 'ana.demo@example.test', 'activo' => false]);
        $datos = ['vigente_desde' => '2026-10-18', 'vigente_hasta' => null, 'motivo' => 'Primer acceso del asistente'];
        $r = $this->postJson('/api/v1/accesos-personal/'.$persona->id, $datos)->assertCreated();
        $clave = $r->json('clave_temporal');
        $this->assertNotEmpty($clave);
        $persona->refresh();
        $this->assertTrue($persona->activo);
        $this->assertTrue($persona->debe_cambiar_password);
        $this->assertTrue(Hash::check($clave, $persona->password));
        $this->assertTrue($persona->tienePermiso('documento.subir'));
        $this->assertFalse($persona->tienePermiso('documento.revisar'));
        $evento = AuditoriaEvento::query()->where('accion', 'acceso.cuenta_habilitada')->sole();
        $this->assertSame($actor->id, $evento->actor_usuario_id);
        $this->assertFalse(json_decode($evento->datos_antes, true)['activo']);
        $this->assertTrue(json_decode($evento->datos_despues, true)['activo']);
        $this->assertStringNotContainsString($clave, AuditoriaEvento::query()->get()->toJson());
        $this->postJson('/api/v1/accesos-personal/'.$persona->id, $datos)->assertConflict();
        $this->assertTrue(Hash::check($clave, $persona->fresh()->password));
    }

    public function test_invalid_email_or_conflicting_assignment_does_not_enable_an_inactive_account(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['email_institucional' => 'correo-invalido', 'activo' => false]);
        $hash = $persona->password;
        $url = '/api/v1/accesos-personal/'.$persona->id;
        $this->postJson($url, $this->datos())->assertUnprocessable()->assertJsonValidationErrors('email_institucional');
        $this->assertFalse($persona->fresh()->activo);
        $this->assertSame($hash, $persona->fresh()->password);
        $persona->update(['email_institucional' => 'apoyo.inactivo@example.test']);
        $this->postJson($url, $this->datos())->assertCreated();
        $persona->refresh()->update(['activo' => false]);
        $this->postJson($url, $this->datos())->assertConflict();
        $this->assertFalse($persona->fresh()->activo);
        $this->assertSame(1, AuditoriaEvento::query()->where('accion', 'acceso.cuenta_habilitada')->count());
    }
}
