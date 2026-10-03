<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResourceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actuarComo();
    }

    public static function resources(): array
    {
        $resources = [];
        foreach (glob(__DIR__.'/../../app/Models/*.php') as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (! ctype_alnum($name) || in_array($name, ['Usuario', 'User', ...array_keys(self::anulables())], true)) {
                continue;
            }
            $resources[$name] = ['App\\Models\\'.$name];
        }

        return $resources;
    }

    /** Secciones 01 y 07: registros que solo se crean y se anulan. */
    public static function anulables(): array
    {
        return collect(['EvaluacionDesempeno', 'ProgresionCarrera', 'RatificacionDocente', 'Desplazamiento', 'DeclaracionVerificacion', 'CertificadoDiscapacidad'])
            ->mapWithKeys(fn (string $name): array => [$name => ['App\\Models\\'.$name]])->all();
    }

    #[DataProvider('anulables')]
    public function test_section_07_records_are_annulled_never_edited_or_deleted(string $class): void
    {
        $autor = $this->actuarComo();
        $model = $class::factory()->make();
        $payload = array_diff_key($model->getAttributes(), array_flip(['id', 'registrado_por', 'anulado', 'motivo_anulacion']));
        $uri = '/api/v1/'.str_replace('_', '-', $model->getTable());

        $this->postJson($uri, $payload + ['registrado_por' => Usuario::factory()->create()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('registrado_por');
        $this->postJson($uri, $payload + ['anulado' => true])->assertUnprocessable()->assertJsonValidationErrors('anulado');
        $id = $this->postJson($uri, $payload)->assertCreated()->assertJsonPath('data.registrado_por', $autor->id)->json('data.id');
        $this->getJson($uri)->assertOk()->assertJsonPath('data.0.id', $id);

        $this->patchJson($uri.'/'.$id, $payload)->assertUnprocessable();
        $this->patchJson($uri.'/'.$id, ['anulado' => true])->assertUnprocessable()->assertJsonValidationErrors('motivo_anulacion');
        $this->patchJson($uri.'/'.$id, ['anulado' => false, 'motivo_anulacion' => 'Sin motivo real'])->assertUnprocessable();
        $this->deleteJson($uri.'/'.$id)->assertStatus(405);
        $this->assertDatabaseHas($model->getTable(), ['id' => $id, 'anulado' => false]);

        $this->patchJson($uri.'/'.$id, ['anulado' => true, 'motivo_anulacion' => 'Registrado por error'])
            ->assertOk()->assertJsonPath('data.anulado', true)->assertJsonPath('data.motivo_anulacion', 'Registrado por error');
        $this->patchJson($uri.'/'.$id, ['anulado' => true, 'motivo_anulacion' => 'Segundo intento'])->assertConflict();
        // El anulado sigue en la base, pero ya no aparece en el listado.
        $this->assertDatabaseHas($model->getTable(), ['id' => $id, 'anulado' => true]);
        $this->getJson($uri)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($uri.'/'.$id)->assertOk();
    }

    #[DataProvider('resources')]
    public function test_resource_can_be_created_read_updated_and_deleted(string $class): void
    {
        $model = $class::factory()->make();
        $payload = $model->getAttributes();
        if ($model instanceof Familiar) {
            $this->actuarComo(Usuario::findOrFail($payload['registrado_por']));
            unset($payload['registrado_por']);
        }
        $uri = '/api/v1/'.str_replace('_', '-', $model->getTable());
        $response = $this->postJson($uri, $payload)->assertCreated();
        $id = $response->json('data.id');
        $this->assertDatabaseHas($model->getTable(), ['id' => $id]);
        $this->getJson($uri.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson($uri)->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
        unset($payload['id']);
        if ($model instanceof DocumentoUsuario || $model instanceof ArchivoDocumento) {
            // Documentos y archivos: no se editan ni se eliminan (Directiva, arts. 2.3 y 5.3).
            $this->patchJson($uri.'/'.$id, $payload)->assertStatus(405);
            $this->deleteJson($uri.'/'.$id)->assertStatus(405);
            $this->assertDatabaseHas($model->getTable(), ['id' => $id]);

            return;
        }
        $this->patchJson($uri.'/'.$id, $payload)->assertOk();
        if ($model instanceof Familiar) {
            // Los familiares se corrigen, pero no se eliminan (se anulan desde la ficha).
            $this->deleteJson($uri.'/'.$id)->assertStatus(405);
            $this->assertDatabaseHas($model->getTable(), ['id' => $id]);

            return;
        }
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
