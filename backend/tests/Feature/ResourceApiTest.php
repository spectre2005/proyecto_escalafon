<?php

namespace Tests\Feature;

use App\Models\Afiliacion;
use App\Models\ArchivoDocumento;
use App\Models\ConexionCorreo;
use App\Models\ConfiguracionFuncionalidad;
use App\Models\Controversia;
use App\Models\Desvinculacion;
use App\Models\Desplazamiento;
use App\Models\DocumentoUsuario;
use App\Models\EvaluacionDesempeno;
use App\Models\Familiar;
use App\Models\GoceVacacional;
use App\Models\Induccion;
use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\LicenciaPermiso;
use App\Models\PerfilAdministrativo;
use App\Models\PerfilDocente;
use App\Models\PeriodoPrueba;
use App\Models\PeriodoVacacional;
use App\Models\ProgresionCarrera;
use App\Models\RatificacionDocente;
use App\Models\Reconocimiento;
use App\Models\RecursoImpugnatorio;
use App\Models\RegistroSstBienestar;
use App\Models\Rol;
use App\Models\Sancion;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
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

    /** Sección 02: el CRUD genérico solo lista y lee; no crea, no edita ni elimina. */
    private const SECCION_02 = [VinculoLaboral::class, VinculoAdenda::class, Induccion::class, PeriodoPrueba::class, PerfilDocente::class, PerfilAdministrativo::class];

    /** Sección 05: igual, se registran y anulan solo desde Seccion05Controller. */
    private const SECCION_05 = [LicenciaPermiso::class, PeriodoVacacional::class, GoceVacacional::class];

    /** Sección 07: igual, se registran, corrigen y anulan solo desde Legajos\Seccion07Controller. */
    private const SECCION_07 = [EvaluacionDesempeno::class, ProgresionCarrera::class, RatificacionDocente::class, Desplazamiento::class];

    /** Sección 08: igual, se registran desde Legajos\Seccion08Controller (sanciones exige además seccion08.sanciones.ver). */
    private const SECCION_08 = [Reconocimiento::class, Sancion::class];

    /** Sección 09: igual, desde Legajos\Seccion09Controller (y el CRUD genérico exige además datos_sensibles.ver). */
    private const SECCION_09 = [Afiliacion::class, Controversia::class];

    /**
     * Modelos que deliberadamente NO tienen CRUD genérico (ni lectura): solo existen dentro de su sección.
     * RecursoImpugnatorio (sección 08): se registra únicamente desde Legajos\Seccion08Controller al subir la resolución
     * del recurso. ConfiguracionFuncionalidad: solo cambia por App\Support\ConfiguracionFuncionalidades, que exige
     * motivo, comprueba las restricciones y audita. No participan de test_resource_can_be_created_read_updated_and_deleted;
     * en su lugar, test_models_without_generic_crud_have_no_endpoint verifica que no exista ningún endpoint genérico.
     * Siguen cubiertos por ModelPersistenceTest.
     */
    private const SIN_CRUD_GENERICO = [RecursoImpugnatorio::class, ConfiguracionFuncionalidad::class, ConexionCorreo::class];

    /** Sección 10: igual, se registran desde Legajos\Seccion10Controller (el CRUD genérico exige además datos_sensibles.ver). */
    private const SECCION_10 = [RegistroSstBienestar::class];

    /** Sección 11: igual, desde Legajos\Seccion11Controller. */
    private const SECCION_11 = [Desvinculacion::class];

    public static function resources(): array
    {
        $resources = [];
        foreach (glob(__DIR__.'/../../app/Models/*.php') as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (! ctype_alnum($name) || in_array($name, ['Usuario', 'User', ...array_keys(self::anulables())], true)
                || in_array('App\\Models\\'.$name, self::SIN_CRUD_GENERICO, true)) {
                continue;
            }
            $resources[$name] = ['App\\Models\\'.$name];
        }

        return $resources;
    }

    /** Modelos sin CRUD genérico (SIN_CRUD_GENERICO). */
    public static function sinCrudGenerico(): array
    {
        return collect(self::SIN_CRUD_GENERICO)->mapWithKeys(fn (string $clase): array => [class_basename($clase) => [$clase]])->all();
    }

    #[DataProvider('sinCrudGenerico')]
    public function test_models_without_generic_crud_have_no_endpoint(string $class): void
    {
        $model = $class::factory()->create();
        $uri = '/api/v1/'.str_replace('_', '-', $model->getTable());

        // Ninguna operación genérica existe: ni listar, ni leer, ni crear, ni editar, ni eliminar.
        $this->getJson($uri)->assertNotFound();
        $this->getJson($uri.'/'.$model->getKey())->assertNotFound();
        $this->postJson($uri, [])->assertNotFound();
        $this->patchJson($uri.'/'.$model->getKey(), [])->assertNotFound();
        $this->deleteJson($uri.'/'.$model->getKey())->assertNotFound();
        $this->assertDatabaseHas($model->getTable(), ['id' => $model->getKey()]);
    }

    /** Sección 01: registros que solo se crean y se anulan. Los de la 07 pasaron a solo lectura (SECCION_07). */
    public static function anulables(): array
    {
        return collect(['DeclaracionVerificacion', 'CertificadoDiscapacidad'])
            ->mapWithKeys(fn (string $name): array => [$name => ['App\\Models\\'.$name]])->all();
    }

    #[DataProvider('anulables')]
    public function test_annullable_records_are_annulled_never_edited_or_deleted(string $class): void
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
        $soloLectura = $model instanceof DocumentoUsuario || $model instanceof ArchivoDocumento || in_array($class, [...self::SECCION_02, ...self::SECCION_05, ...self::SECCION_07, ...self::SECCION_08, ...self::SECCION_09, ...self::SECCION_10, ...self::SECCION_11], true);
        if ($soloLectura) {
            // Documentos y archivos se crean únicamente por la carga del legajo (folios, hash y bitácora); los registros de
            // las secciones 02 y 05, solo desde Seccion02Controller y Seccion05Controller.
            $this->postJson($uri, $payload)->assertStatus(405);
            $model->save();
        }
        $id = $soloLectura ? $model->getKey() : $this->postJson($uri, $payload)->assertCreated()->json('data.id');
        $this->assertDatabaseHas($model->getTable(), ['id' => $id]);
        $this->getJson($uri.'/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson($uri)->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
        unset($payload['id']);
        if ($soloLectura) {
            // No se editan ni se eliminan por el CRUD genérico (Directiva, arts. 2.3 y 5.3). Los de la sección 02 se
            // corrigen y se anulan solo desde Seccion02Controller.
            $this->patchJson($uri.'/'.$id, $payload)->assertStatus(405);
            $this->putJson($uri.'/'.$id, $payload)->assertStatus(405);
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
