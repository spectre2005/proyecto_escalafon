<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResourceApiTest extends TestCase
{
    use RefreshDatabase;

    public static function resources(): array
    {
        $resources = [];
        foreach (glob(__DIR__.'/../../app/Models/*.php') as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (! ctype_alnum($name) || in_array($name, ['Usuario', 'User'], true)) {
                continue;
            }
            $resources[$name] = ['App\\Models\\'.$name];
        }

        return $resources;
    }

    #[DataProvider('resources')]
    public function test_resource_can_be_created_read_updated_and_deleted(string $class): void
    {
        $model = $class::factory()->make();
        $payload = $model->getAttributes();
        $uri = '/api/v1/'.str_replace('_', '-', $model->getTable());
        $response = $this->postJson($uri, $payload)->assertCreated();
        $id = $response->json('data.id');
        $this->assertDatabaseHas($model->getTable(), ['id' => $id]);
        $this->getJson($uri.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson($uri)->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
        unset($payload['id']);
        $this->patchJson($uri.'/'.$id, $payload)->assertOk();
        $this->deleteJson($uri.'/'.$id)->assertNoContent();
        $this->assertDatabaseMissing($model->getTable(), ['id' => $id]);
        $this->getJson($uri.'/'.$id)->assertNotFound();
    }

    public function test_validation_rejects_missing_fields_unknown_fields_and_invalid_relations(): void
    {
        $this->postJson('/api/v1/roles', [])->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->postJson('/api/v1/roles', ['password' => 'secret'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/legajos', [
            'usuario_id' => '00000000-0000-4000-8000-000000000000',
            'numero_legajo' => 'L-001', 'fecha_apertura' => '2026-09-30',
        ])->assertUnprocessable()->assertJsonValidationErrors('usuario_id');
        $this->assertDatabaseCount('legajos', 0);
    }

    public function test_update_persists_changes_and_rejects_primary_key_changes(): void
    {
        $rol = Rol::factory()->create();
        $this->patchJson('/api/v1/roles/'.$rol->id, ['nombre' => 'Nuevo nombre'])
            ->assertOk()->assertJsonPath('data.nombre', 'Nuevo nombre');
        $this->assertDatabaseHas('roles', ['id' => $rol->id, 'nombre' => 'Nuevo nombre']);
        $this->patchJson('/api/v1/roles/'.$rol->id, ['id' => 'replacement'])->assertUnprocessable();
    }

    public function test_related_records_cannot_be_deleted(): void
    {
        $legajo = Legajo::factory()->create();
        LegajoSeccion::factory()->create(['legajo_id' => $legajo->id]);
        $this->deleteJson('/api/v1/legajos/'.$legajo->id)->assertConflict();
        $this->assertDatabaseHas('legajos', ['id' => $legajo->id]);
    }

    public function test_resource_routes_are_disabled_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->getJson('/api/v1/roles')->assertForbidden();
        $this->postJson('/api/v1/roles', [])->assertForbidden();
        $this->patchJson('/api/v1/roles/00000000-0000-4000-8000-000000000000', [])->assertForbidden();
        $this->deleteJson('/api/v1/roles/00000000-0000-4000-8000-000000000000')->assertForbidden();
    }
}
