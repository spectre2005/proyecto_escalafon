<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsuariosPaginacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagination_is_bounded_and_pages_do_not_overlap(): void
    {
        $this->actuarComo();
        Usuario::factory()->count(115)->create();
        $first = $this->getJson('/api/v1/usuarios?per_page=10')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 116)->assertJsonPath('resumen.total', 116)->json('data');
        $second = $this->getJson('/api/v1/usuarios?per_page=10&page=2')->assertOk()->assertJsonCount(10, 'data')->json('data');
        $this->assertSame([], array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        $this->getJson('/api/v1/usuarios?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/usuarios?page=-1')->assertUnprocessable();
    }

    public function test_filters_apply_before_pagination_and_wildcards_are_literal(): void
    {
        $this->actuarComo();
        $wanted = Usuario::factory()->create(['nombres' => 'Nombre Unico', 'apellido_paterno' => 'Familia', 'tipo_personal' => 'docente', 'activo' => false]);
        Usuario::factory()->create(['nombres' => 'Nombre Unico', 'apellido_paterno' => 'Familia', 'tipo_personal' => 'administrativo', 'activo' => true]);
        $this->getJson('/api/v1/usuarios?q=nombre%20unico%20familia&tipo_personal=docente&estado=inactivo&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->id)->assertJsonPath('meta.total', 1);
        $literal = Usuario::factory()->create(['nombres' => 'Literal %_ especial']);
        $this->getJson('/api/v1/usuarios?q=%25_')->assertOk()->assertJsonPath('data.0.id', $literal->id)->assertJsonPath('meta.total', 1);
    }

    public function test_summary_does_not_return_personal_rows_and_requires_permission(): void
    {
        $admin = $this->actuarComo();
        $admin->update(['tipo_personal' => 'docente', 'activo' => true]);
        Usuario::factory()->create(['tipo_personal' => 'administrativo', 'activo' => false]);
        $this->getJson('/api/v1/usuarios/resumen')->assertOk()->assertExactJson(['data' => [
            'total' => 2, 'activos' => 1, 'inactivos' => 1, 'docentes' => 1, 'administrativos' => 1, 'con_correo' => 2,
        ]]);
        $this->actuarComo(null, 'personal');
        $this->getJson('/api/v1/usuarios/resumen')->assertForbidden();
    }
}
