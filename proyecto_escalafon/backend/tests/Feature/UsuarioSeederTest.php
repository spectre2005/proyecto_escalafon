<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsuarioSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_ten_fictional_users_repeatably_for_dashboard_and_directory(): void
    {
        $this->seed(UsuarioSeeder::class);
        $this->seed(UsuarioSeeder::class);

        $this->assertDatabaseCount('usuarios', 10);
        $this->assertSame(3, Usuario::query()->where('activo', true)->count());
        $this->assertSame(7, Usuario::query()->where('activo', false)->count());
        $this->assertSame(5, Usuario::query()->where('tipo_personal', 'docente')->count());
        $this->assertSame(5, Usuario::query()->where('tipo_personal', 'administrativo')->count());
        $this->assertSame(10, Usuario::query()->where('email_institucional', 'like', '%@example.test')->count());
    }

    public function test_it_does_not_seed_demo_users_outside_local_or_testing(): void
    {
        $this->app->instance('env', 'production');

        $this->app->make(UsuarioSeeder::class)->run();

        $this->assertDatabaseCount('usuarios', 0);
    }
}
