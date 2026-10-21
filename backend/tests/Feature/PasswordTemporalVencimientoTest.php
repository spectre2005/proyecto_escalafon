<?php

namespace Tests\Feature;

use App\Models\Plataforma\AdministradorPlataforma;
use App\Models\Usuario;
use App\Support\PasswordTemporal;
use App\Support\Plataforma\AdministradoresInstitucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

class PasswordTemporalVencimientoTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 12:00:00');
    }

    private function temporal(): Usuario
    {
        $usuario = $this->asignarRol(Usuario::factory()->create(['password' => 'Temporal-prueba-2026']))->refresh();
        $usuario->forceFill(['debe_cambiar_password' => true])->save();

        return $usuario;
    }

    public function test_both_models_assign_a_deadline_and_unrelated_saves_do_not_extend_it(): void
    {
        $usuario = $this->temporal();
        $super = AdministradorPlataforma::query()->create(['nombre' => 'Nuevo administrador', 'email' => 'nuevo@example.test',
            'password' => 'Temporal-prueba-2026', 'activo' => true, 'debe_cambiar_password' => true]);
        foreach ([$usuario, $super] as $cuenta) {
            $this->assertSame(now()->addHours(48)->timestamp, $cuenta->password_temporal_vence_en->timestamp);
            $fin = $cuenta->password_temporal_vence_en->timestamp;
            $this->travel(1)->hour();
            $cuenta->forceFill(['ultimo_acceso' => now()])->save();
            $this->assertSame($fin, $cuenta->fresh()->password_temporal_vence_en->timestamp);
            $cuenta->forceFill(['debe_cambiar_password' => false])->save();
            $this->assertNull($cuenta->fresh()->password_temporal_vence_en);
            $this->travelBack();
            $this->travelTo('2026-10-20 12:00:00');
        }
    }

    public function test_an_expired_institutional_password_cannot_log_in_and_reset_renews_the_deadline(): void
    {
        $usuario = $this->temporal();
        $this->travel(48)->hours();
        $this->postJson('/api/v1/auth/login', ['email' => $usuario->email_institucional, 'password' => 'Temporal-prueba-2026'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', PasswordTemporal::MENSAJE);
        [$actualizado, $clave] = DB::transaction(fn () => AdministradoresInstitucion::restablecer($usuario->id, 72));
        $this->assertSame(now()->addHours(72)->timestamp, $actualizado->password_temporal_vence_en->timestamp);
        $this->postJson('/api/v1/auth/login', ['email' => $usuario->email_institucional, 'password' => 'Temporal-prueba-2026'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => $usuario->email_institucional, 'password' => $clave])
            ->assertOk()->assertJsonPath('data.debe_cambiar_password', true);
    }

    public function test_an_open_institutional_session_cannot_change_the_password_after_the_deadline(): void
    {
        $usuario = $this->temporal();
        Sanctum::actingAs($usuario);
        $this->travel(48)->hours();
        $this->putJson('/api/v1/auth/password', ['password_actual' => 'Temporal-prueba-2026',
            'password' => 'Nueva-personal-2026', 'password_confirmation' => 'Nueva-personal-2026'])->assertUnauthorized();
        $this->assertTrue($usuario->fresh()->debe_cambiar_password);
    }

    public function test_changing_before_the_deadline_removes_the_limit_permanently(): void
    {
        $usuario = $this->temporal();
        Sanctum::actingAs($usuario);
        $this->travel(47)->hours();
        $this->putJson('/api/v1/auth/password', ['password_actual' => 'Temporal-prueba-2026',
            'password' => 'Nueva-personal-2026', 'password_confirmation' => 'Nueva-personal-2026'])->assertOk();
        $this->assertNull($usuario->fresh()->password_temporal_vence_en);
        $this->travel(8)->days();
        $this->assertFalse(PasswordTemporal::vencida($usuario->fresh()));
    }

    public function test_the_platform_can_choose_the_limit_and_reissue_an_expired_temporary_password(): void
    {
        $operador = $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => $operador->email, 'password' => 'Clave-segura-2026'])->assertOk();
        $url = '/api/plataforma/superadministradores';
        $datos = ['nombre' => 'Cuenta nueva', 'email' => 'nueva@example.test', 'vigencia_horas' => 24];
        $this->postJson($url, [...$datos, 'vigencia_horas' => 0])->assertUnprocessable();
        $creada = $this->postJson($url, $datos)->assertCreated();
        $id = $creada->json('data.id');
        $this->assertSame(now()->addHours(24)->timestamp, AdministradorPlataforma::query()->findOrFail($id)->password_temporal_vence_en->timestamp);
        $this->postJson($url.'/'.$id.'/restablecer', ['vigencia_horas' => 24])->assertOk();
        $this->assertSame(now()->addHours(24)->timestamp, AdministradorPlataforma::query()->findOrFail($id)->password_temporal_vence_en->timestamp);
        $this->travel(25)->hours();
        $this->getJson($url)->assertOk()->assertJsonFragment(['password_temporal_vencida' => true]);
        $this->postJson($url.'/'.$id.'/restablecer', ['vigencia_horas' => 72])->assertOk()->assertJsonPath('data.password_temporal_vencida', false);
        $this->assertSame(now()->addHours(72)->timestamp, AdministradorPlataforma::query()->findOrFail($id)->password_temporal_vence_en->timestamp);
    }

    public function test_a_platform_session_opened_before_expiration_is_rejected_at_the_exact_deadline(): void
    {
        $cuenta = $this->administrador();
        $cuenta->forceFill(['debe_cambiar_password' => true])->save();
        $this->postJson('/api/plataforma/auth/login', ['email' => $cuenta->email, 'password' => 'Clave-segura-2026'])->assertOk();
        $this->travel(48)->hours();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/plataforma/auth/me')->assertUnauthorized();
        $this->postJson('/api/plataforma/auth/login', ['email' => $cuenta->email, 'password' => 'Clave-segura-2026'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', PasswordTemporal::MENSAJE);
    }

    public function test_calendar_dates_are_stored_as_the_exact_instant_selected_in_lima(): void
    {
        $operador = $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => $operador->email, 'password' => 'Clave-segura-2026'])->assertOk();
        $url = '/api/plataforma/superadministradores';
        $datos = ['nombre' => 'Cuenta calendario', 'email' => 'calendario@example.test', 'vence_en' => '2026-10-22T23:59:59-05:00'];
        $id = $this->postJson($url, $datos)->assertCreated()->json('data.id');
        $cuenta = AdministradorPlataforma::query()->findOrFail($id);
        $this->assertSame('2026-10-23T04:59:59+00:00', $cuenta->password_temporal_vence_en->utc()->toIso8601String());
        $this->postJson($url.'/'.$id.'/restablecer', ['vence_en' => '2026-10-27T23:59:59-05:00'])->assertOk();
        $this->travelTo('2026-10-28 04:59:58');
        $this->assertFalse(PasswordTemporal::vencida($cuenta->fresh()));
        $this->travel(1)->second();
        $this->assertTrue(PasswordTemporal::vencida($cuenta->fresh()));
    }

    public function test_past_ambiguous_or_overlong_calendar_deadlines_are_rejected_without_creating_accounts(): void
    {
        $operador = $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => $operador->email, 'password' => 'Clave-segura-2026'])->assertOk();
        $datos = ['nombre' => 'Cuenta inválida', 'email' => 'invalida@example.test'];
        foreach (['2026-10-19T23:59:59-05:00', '2026-10-28T23:59:59-05:00', '2026-10-22', null] as $fecha) {
            $this->postJson('/api/plataforma/superadministradores', [...$datos, 'vence_en' => $fecha])
                ->assertUnprocessable()->assertJsonValidationErrors('vence_en');
        }
        $this->postJson('/api/plataforma/superadministradores', [...$datos, 'vence_en' => '2026-10-22T23:59:59-05:00', 'vigencia_horas' => 48])->assertUnprocessable();
        $this->assertSame(1, AdministradorPlataforma::query()->count());
    }
}
