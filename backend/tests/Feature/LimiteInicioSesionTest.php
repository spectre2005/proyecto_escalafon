<?php

namespace Tests\Feature;

use App\Support\LimiteInicioSesion;
use Database\Seeders\AdministradorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/** Límite de intentos de inicio de sesión: por correo e IP y, además, por cuenta aunque cambie la IP. */
class LimiteInicioSesionTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const EMAIL = 'admin@example.test';

    private const CLAVE = 'Clave-segura-2026';

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.administrador_inicial' => ['email' => self::EMAIL, 'password' => self::CLAVE],
            'sanctum.stateful' => ['localhost:5173'], 'cache.limiter' => 'array']);
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->seed(AdministradorSeeder::class);
    }

    private function intento(string $clave, string $ip, string $email = self::EMAIL)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $clave]);
    }

    public function test_the_same_ip_is_blocked_after_five_failures(): void
    {
        for ($i = 0; $i < LimiteInicioSesion::INTENTOS_POR_IP; $i++) {
            $this->intento('mala', '10.0.0.1')->assertUnprocessable();
        }
        $this->intento(self::CLAVE, '10.0.0.1')->assertStatus(429)->assertHeader('Retry-After');
        // Desde otra IP la persona real todavía entra.
        $this->intento(self::CLAVE, '10.0.0.2')->assertOk();
    }

    public function test_changing_ip_does_not_allow_unlimited_guesses_against_one_account(): void
    {
        for ($i = 1; $i <= LimiteInicioSesion::INTENTOS_POR_CUENTA; $i++) {
            $this->intento('mala', '10.1.0.'.$i)->assertUnprocessable();
        }
        // Desde una IP nueva y con la clave correcta: la cuenta espera; otra cuenta no se ve afectada.
        $this->intento(self::CLAVE, '10.2.0.1')->assertStatus(429);
        $this->intento('mala', '10.2.0.1', 'otra@example.test')->assertUnprocessable();
    }

    public function test_a_successful_login_resets_the_counters(): void
    {
        for ($i = 1; $i < LimiteInicioSesion::INTENTOS_POR_IP; $i++) {
            $this->intento('mala', '10.3.0.1')->assertUnprocessable();
        }
        $this->intento(self::CLAVE, '10.3.0.1')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        for ($i = 1; $i < LimiteInicioSesion::INTENTOS_POR_IP; $i++) {
            $this->intento('mala', '10.3.0.1')->assertUnprocessable();
        }
        $this->intento(self::CLAVE, '10.3.0.1')->assertOk();
    }

    public function test_the_platform_login_also_limits_by_account(): void
    {
        $this->administrador();
        for ($i = 1; $i <= LimiteInicioSesion::INTENTOS_POR_CUENTA; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.4.0.'.$i])->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'mala'])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.5.0.1'])->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertStatus(429);
    }
}
