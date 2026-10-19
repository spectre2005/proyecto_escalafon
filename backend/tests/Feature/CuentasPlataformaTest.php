<?php

namespace Tests\Feature;

use App\Models\Plataforma\AdministradorPlataforma;
use App\Models\Plataforma\AuditoriaPlataforma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

class CuentasPlataformaTest extends TestCase
{
    use RefreshDatabase, UsaPlataforma;

    public function test_accounts_are_separate_audited_and_keep_an_active_administrator(): void
    {
        $actor = $this->administrador();
        $this->actingAs($actor, 'plataforma');
        $this->patchJson('/api/plataforma/cuentas/'.$actor->id.'/estado', ['activo' => false, 'motivo' => 'Desactivar la última cuenta'])->assertConflict();
        $datos = ['nombre' => 'Otra administradora', 'email' => ' NUEVA@example.test ', 'password' => 'Prueba-Cuenta-2026', 'password_confirmation' => 'Prueba-Cuenta-2026', 'motivo' => 'Alta de cuenta de soporte autorizada'];
        $id = $this->postJson('/api/plataforma/cuentas', $datos)->assertCreated()->assertJsonMissingPath('data.password')->json('data.id');
        $this->postJson('/api/plataforma/cuentas', $datos)->assertUnprocessable();
        $this->patchJson('/api/plataforma/cuentas/'.$actor->id.'/estado', ['activo' => false, 'motivo' => 'Evitar desactivar mi propia sesión'])->assertConflict();
        $this->postJson('/api/plataforma/cuentas/'.$id.'/restablecer', ['password' => 'Nueva-Cuenta-2026', 'password_confirmation' => 'Nueva-Cuenta-2026', 'motivo' => 'Restablecimiento solicitado por titular'])->assertOk();
        $this->assertTrue(Hash::check('Nueva-Cuenta-2026', AdministradorPlataforma::query()->findOrFail($id)->password));
        $this->patchJson('/api/plataforma/cuentas/'.$id.'/estado', ['activo' => false, 'motivo' => 'Suspensión por fin de soporte'])->assertOk();
        $this->patchJson('/api/plataforma/cuentas/'.$id.'/estado', ['activo' => true, 'motivo' => 'Reincorporación del soporte autorizado'])->assertOk();
        $this->getJson('/api/plataforma/cuentas')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.password');
        $json = AuditoriaPlataforma::query()->get()->toJson();
        $this->assertStringNotContainsString('Prueba-Cuenta-2026', $json);
        $this->assertStringNotContainsString('Nueva-Cuenta-2026', $json);
        $this->getJson('/api/v1/expedientes')->assertUnauthorized();
    }

    public function test_institutional_sessions_cannot_manage_platform_accounts(): void
    {
        $this->actuarComo();
        $this->getJson('/api/plataforma/cuentas')->assertUnauthorized();
        $this->postJson('/api/plataforma/cuentas', [])->assertUnauthorized();
    }
}
