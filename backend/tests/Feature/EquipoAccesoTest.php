<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EquipoAccesoTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/equipo-accesos';

    private function datos(Usuario $usuario, array $cambios = []): array
    {
        return $cambios + ['usuario_id' => $usuario->id, 'perfil' => 'asistente_legajo',
            'vigente_desde' => today()->toDateString(), 'vigente_hasta' => null, 'motivo' => 'Apoyo autorizado por la administración'];
    }

    public function test_only_the_institution_administrator_can_manage_the_team(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        foreach (['asistente_legajo', 'suplente_legajo', 'configuracion_modulos'] as $codigo) {
            $this->actuarComo(codigo: $codigo);
            $this->getJson(self::URL)->assertForbidden();
            $this->getJson(self::URL.'/personas?q=persona')->assertForbidden();
            $this->postJson(self::URL, [])->assertForbidden();
        }
        $this->actuarComo();
        $this->getJson(self::URL)->assertOk()->assertJsonCount(3, 'data.perfiles');
    }

    public function test_assigning_an_existing_person_preserves_their_account_and_uses_the_authenticated_actor(): void
    {
        $admin = $this->actuarComo();
        $usuario = Usuario::factory()->create();
        $clave = $usuario->password;
        $this->postJson(self::URL, $this->datos($usuario))->assertCreated()->assertJsonPath('credenciales', null)
            ->assertJsonPath('data.estado', 'vigente')->assertJsonPath('data.persona.id', $usuario->id);
        $this->assertSame($clave, $usuario->fresh()->password);
        $asignacion = UsuarioRol::query()->where('usuario_id', $usuario->id)->sole();
        $this->assertSame($admin->id, $asignacion->asignado_por);
        $this->assertEqualsCanonicalizing(config('permisos.roles.asistente_legajo'), $usuario->fresh()->permisos());
        $evento = AuditoriaEvento::query()->where('entidad', 'equipo_accesos')->sole();
        $this->assertSame($admin->id, $evento->actor_usuario_id);
        $this->assertSame($usuario->id, $evento->usuario_titular_id);
        $this->postJson(self::URL, $this->datos($usuario))->assertConflict();
    }

    public function test_creating_an_account_returns_a_temporary_password_without_creating_a_legajo(): void
    {
        $this->actuarComo();
        $data = ['nombres' => 'Ana', 'apellido_paterno' => 'Quispe', 'numero_doc_identidad' => '99887766',
            'email' => 'Ana.Equipo@example.test', 'perfil' => 'suplente_legajo',
            'vigente_desde' => today()->toDateString(), 'vigente_hasta' => today()->addWeek()->toDateString(), 'motivo' => 'Suplencia durante vacaciones'];
        $respuesta = $this->postJson(self::URL, $data)->assertCreated();
        $usuario = Usuario::query()->findOrFail($respuesta->json('data.persona.id'));
        $clave = $respuesta->json('credenciales.password_temporal');
        $this->assertTrue(Hash::check($clave, $usuario->password));
        $this->assertTrue($usuario->debe_cambiar_password);
        $this->assertDatabaseCount('legajos', 0);
        $this->assertDatabaseCount('vinculos_laborales', 0);
        $this->assertStringNotContainsString($clave, AuditoriaEvento::query()->where('entidad', 'equipo_accesos')->sole()->datos_despues);
        $this->getJson(self::URL)->assertOk()->assertJsonMissingPath('data.asignaciones.0.password');
        Sanctum::actingAs($usuario->fresh());
        $this->getJson('/api/v1/expedientes')->assertForbidden()->assertJsonPath('codigo', 'cambio_password_requerido');
    }

    public function test_suplente_requires_an_end_date_and_neither_profile_can_escalate_privileges(): void
    {
        $admin = $this->actuarComo();
        $usuario = Usuario::factory()->create();
        $this->postJson(self::URL, $this->datos($usuario, ['perfil' => 'suplente_legajo']))->assertUnprocessable();
        $this->postJson(self::URL, $this->datos($usuario, ['perfil' => 'ADMIN']))->assertUnprocessable();
        $this->postJson(self::URL, $this->datos($usuario, ['ve_datos_sensibles' => true]))->assertUnprocessable();
        $this->postJson(self::URL, $this->datos($usuario, ['asignado_por' => $usuario->id]))->assertUnprocessable();
        $this->postJson(self::URL, $this->datos($usuario, ['perfil' => 'suplente_legajo', 'vigente_hasta' => today()->addDay()->toDateString()]))->assertCreated();
        $suplente = $usuario->fresh();
        $this->assertTrue($suplente->tienePermiso('documento.revisar'));
        foreach (['sistema.administrar', 'usuarios.gestionar', 'equipo.gestionar', 'configuracion.modulos', 'datos_sensibles.ver'] as $permiso) {
            $this->assertFalse($suplente->tienePermiso($permiso));
        }
        Sanctum::actingAs($suplente);
        $this->getJson('/api/v1/usuario-roles')->assertForbidden();
        $this->patchJson('/api/v1/usuarios/'.$admin->id, ['activo' => false])->assertForbidden();
        $this->getJson('/api/v1/configuracion/modulos-sistema')->assertForbidden();
        $this->getJson('/api/v1/expedientes/'.$admin->id.'/seccion-01')->assertForbidden();
    }

    public function test_self_management_administrators_and_accounts_with_other_roles_are_protected(): void
    {
        $admin = $this->actuarComo();
        $otro = $this->asignarRol(Usuario::factory()->create());
        $this->postJson(self::URL, $this->datos($admin))->assertConflict();
        $this->postJson(self::URL, $this->datos($otro))->assertConflict();
        $usuario = Usuario::factory()->create(['activo' => false]);
        $this->postJson(self::URL, $this->datos($usuario))->assertConflict();
        $this->assertDatabaseCount('usuario_roles', 2);
    }

    public function test_dates_reasons_duplicates_and_existing_emails_are_validated(): void
    {
        $this->actuarComo();
        $usuario = Usuario::factory()->create(['email_institucional' => 'equipo@example.test']);
        $this->postJson(self::URL, $this->datos($usuario, ['motivo' => '   ']))->assertUnprocessable();
        $this->postJson(self::URL, $this->datos($usuario, ['vigente_desde' => today()->subDay()->toDateString()]))->assertUnprocessable();
        $this->postJson(self::URL, $this->datos($usuario, ['vigente_hasta' => today()->subDay()->toDateString()]))->assertUnprocessable();
        $this->postJson(self::URL, ['nombres' => 'Ana', 'apellido_paterno' => 'Quispe', 'numero_doc_identidad' => '99887766',
            'email' => 'EQUIPO@example.test', 'perfil' => 'asistente_legajo', 'vigente_desde' => today()->toDateString(), 'motivo' => 'Apoyo administrativo'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('usuarios', 2);
    }

    public function test_revocation_preserves_history_and_immediately_removes_permissions(): void
    {
        $admin = $this->actuarComo();
        $usuario = Usuario::factory()->create();
        $id = $this->postJson(self::URL, $this->datos($usuario))->assertCreated()->json('data.id');
        $this->postJson(self::URL.'/'.$id.'/revocar', ['motivo' => 'Fin del apoyo administrativo'])
            ->assertOk()->assertJsonPath('data.estado', 'revocado');
        $this->assertSame([], $usuario->fresh()->permisos());
        $this->assertDatabaseCount('usuario_roles', 2);
        $registro = UsuarioRol::query()->findOrFail($id);
        $this->assertSame($admin->id, $registro->revocado_por);
        $this->patchJson(self::URL.'/'.$id, ['vigente_hasta' => today()->addWeek()->toDateString(), 'motivo' => 'Volver a habilitar'])->assertConflict();
        $this->postJson(self::URL.'/'.$id.'/revocar', ['motivo' => 'Revocación duplicada'])->assertConflict();
        $eventos = AuditoriaEvento::query()->where('entidad', 'equipo_accesos')->orderBy('id')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame($eventos[0]->hash_registro, $eventos[1]->hash_previo);
    }

    public function test_scheduled_and_expired_assignments_grant_no_permissions_and_can_be_renewed(): void
    {
        $this->travelTo(today()->setTime(12, 0));
        $admin = $this->actuarComo();
        $usuario = Usuario::factory()->create();
        $id = $this->postJson(self::URL, $this->datos($usuario, ['perfil' => 'suplente_legajo',
            'vigente_desde' => today()->addDay()->toDateString(), 'vigente_hasta' => today()->addDays(2)->toDateString()]))
            ->assertCreated()->assertJsonPath('data.estado', 'programado')->json('data.id');
        $this->assertSame([], $usuario->fresh()->permisos());
        $this->travel(2)->days();
        $this->assertTrue($usuario->fresh()->tienePermiso('legajo.ver'));
        $this->travel(1)->days();
        $this->assertSame([], $usuario->fresh()->permisos());
        Sanctum::actingAs($admin->fresh());
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.asignaciones.0.estado', 'vencido');
        $this->patchJson(self::URL.'/'.$id, ['vigente_hasta' => null, 'motivo' => 'Renovación del reemplazo'])->assertUnprocessable();
        $this->patchJson(self::URL.'/'.$id, ['vigente_hasta' => today()->addWeek()->toDateString(), 'motivo' => 'Renovación del reemplazo'])->assertOk();
        $this->assertTrue($usuario->fresh()->tienePermiso('legajo.ver'));
    }

    public function test_an_already_open_session_loses_access_after_revocation(): void
    {
        $admin = $this->actuarComo();
        $usuario = Usuario::factory()->create(['password' => 'Clave-segura-2026']);
        $id = $this->postJson(self::URL, $this->datos($usuario))->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->withHeader('Origin', 'http://localhost:5173');
        config(['sanctum.stateful' => ['localhost:5173'], 'cache.limiter' => 'array']);
        $this->postJson('/api/v1/auth/login', ['email' => $usuario->email_institucional, 'password' => 'Clave-segura-2026'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/expedientes')->assertOk();
        UsuarioRol::query()->whereKey($id)->update(['revocado_en' => now(), 'revocado_por' => $admin->id]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/expedientes')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_password_reset_requires_a_reason_and_never_exposes_the_password_in_audit(): void
    {
        $this->actuarComo();
        $usuario = Usuario::factory()->create();
        $id = $this->postJson(self::URL, $this->datos($usuario))->assertCreated()->json('data.id');
        $this->postJson(self::URL.'/'.$id.'/restablecer', [])->assertUnprocessable();
        $respuesta = $this->postJson(self::URL.'/'.$id.'/restablecer', ['motivo' => 'La persona olvidó su contraseña'])->assertOk();
        $clave = $respuesta->json('data.password_temporal');
        $this->assertTrue(Hash::check($clave, $usuario->fresh()->password));
        $this->assertTrue($usuario->fresh()->debe_cambiar_password);
        $this->assertStringNotContainsString($clave, AuditoriaEvento::query()->where('accion', 'equipo.clave_restablecida')->sole()->datos_despues);
        $this->asignarRol($usuario);
        $this->postJson(self::URL.'/'.$id.'/restablecer', ['motivo' => 'Intento de modificar un administrador'])->assertConflict();
    }

    public function test_search_is_limited_and_returns_only_basic_identity_fields(): void
    {
        $admin = $this->actuarComo();
        $usuario = Usuario::factory()->create(['nombres' => 'Asistente Prueba']);
        $this->getJson(self::URL.'/personas?q=A')->assertOk();
        $this->getJson(self::URL.'/personas?q=As')->assertOk();
        $this->getJson(self::URL.'/personas?q=')->assertUnprocessable();
        $this->getJson(self::URL.'/personas?q=Asistente')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $usuario->id)->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.domicilio');
        $this->getJson(self::URL.'/personas?q='.urlencode($admin->email_institucional))->assertOk()->assertJsonCount(0, 'data');
    }
}
