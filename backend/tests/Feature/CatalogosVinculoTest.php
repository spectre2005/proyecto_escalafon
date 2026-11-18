<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\Legajo;
use App\Models\Puesto;
use App\Models\RegimenNormativo;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Listas de puestos y unidades orgánicas (17/11/2026): empiezan vacías, aprenden de los vínculos que se registran (alta
 * manual y sección 02, también el asistente), no duplican por mayúsculas, tildes ni espacios, y solo el administrador
 * las corrige o desactiva. Los vínculos conservan su texto aunque la lista cambie.
 */
class CatalogosVinculoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
    }

    private function alta(string $dni, array $vinculo, string $tipo = 'administrativo'): TestResponse
    {
        return $this->postJson('/api/v1/usuarios/con-vinculo', [
            'usuarios' => [['numero_doc_identidad' => $dni, 'nombres' => 'Ana', 'apellido_paterno' => 'Lista', 'email_institucional' => "p{$dni}@example.test",
                'tipo_personal' => $tipo, 'regimen_normativo_id' => RegimenNormativo::query()->where('codigo', $tipo === 'docente' ? '30220' : '1057')->value('id')]],
            'vinculo' => ['regimen_laboral' => 'plazo_determinado', 'fecha_inicio' => '2026-03-01', ...$vinculo],
            'datos_puesto' => $tipo === 'docente' ? ['condicion_docente' => 'contratado'] : [],
        ]);
    }

    private function personaConLegajo(): Usuario
    {
        $ley = RegimenNormativo::factory()->create(['aplica_a' => 'administrativo']);
        $persona = Usuario::factory()->create(['tipo_personal' => 'administrativo', 'regimen_normativo_id' => $ley->id]);
        Legajo::factory()->create(['usuario_id' => $persona->id]);

        return $persona;
    }

    public function test_lists_start_empty_and_learn_from_the_manual_registration(): void
    {
        $admin = $this->actuarComo();
        $this->getJson('/api/v1/expediente-opciones')->assertOk()->assertJsonCount(0, 'data.puestos')->assertJsonCount(0, 'data.unidades');

        $this->alta('71000011', ['nombre_puesto' => 'Analista  de   personal', 'unidad_organica_nombre' => 'Oficina de Recursos Humanos'])->assertCreated();

        $puesto = Puesto::query()->sole();
        $unidad = UnidadOrganica::query()->sole();
        $this->assertSame(['Analista de personal', 'administrativo', true, $admin->id], [$puesto->nombre, $puesto->tipo_personal, $puesto->activo, $puesto->registrado_por]);
        $this->assertSame(['Oficina de Recursos Humanos', null, null, $admin->id], [$unidad->nombre, $unidad->codigo, $unidad->tipo, $unidad->registrado_por]);
        $vinculo = VinculoLaboral::query()->sole();
        $this->assertSame(['Analista de personal', $unidad->id], [$vinculo->nombre_puesto, $vinculo->unidad_organica_id]);
        $this->getJson('/api/v1/expediente-opciones')->assertJsonPath('data.puestos.0.nombre', 'Analista de personal')
            ->assertJsonPath('data.unidades.0.nombre', 'Oficina de Recursos Humanos');
    }

    public function test_same_name_without_case_accents_or_spaces_is_not_duplicated(): void
    {
        $this->actuarComo();
        $this->alta('71000021', ['nombre_puesto' => 'Analista', 'unidad_organica_nombre' => 'Oficina de Tecnología'])->assertCreated();
        $this->alta('71000022', ['nombre_puesto' => ' ANÁLISTA ', 'unidad_organica_nombre' => 'OFICINA DE  TECNOLOGIA'])->assertCreated();

        $this->assertSame(1, Puesto::query()->count());
        $this->assertSame(1, UnidadOrganica::query()->count());
        // El segundo vínculo toma el nombre y la unidad de la lista.
        $this->assertSame([['Analista'], [UnidadOrganica::query()->value('id')]], [
            VinculoLaboral::query()->pluck('nombre_puesto')->unique()->values()->all(),
            VinculoLaboral::query()->pluck('unidad_organica_id')->unique()->values()->all(),
        ]);
    }

    public function test_the_same_name_is_a_different_post_for_each_staff_type(): void
    {
        $this->actuarComo();
        $this->alta('71000031', ['nombre_puesto' => 'Coordinador'])->assertCreated();
        $this->alta('71000032', ['nombre_puesto' => 'Coordinador', 'regimen_laboral' => 'carrera'], 'docente')->assertCreated();

        $this->assertEqualsCanonicalizing(['administrativo', 'docente'], Puesto::query()->pluck('tipo_personal')->all());
    }

    public function test_assistant_adds_posts_and_units_from_section_02_but_cannot_manage_the_lists(): void
    {
        $this->actuarComo(null, 'asistente_legajo');
        $persona = $this->personaConLegajo();
        $id = $this->postJson("/api/v1/expedientes/{$persona->id}/vinculos-laborales", ['regimen_laboral' => 'plazo_determinado', 'fecha_inicio' => '2026-03-01',
            'nombre_puesto' => 'Técnico de archivo', 'unidad_organica_nombre' => 'Unidad de Archivo'])->assertCreated()->json('data.id');
        $this->assertSame(['Técnico de archivo'], Puesto::query()->pluck('nombre')->all());
        $this->assertSame(UnidadOrganica::query()->sole()->id, VinculoLaboral::query()->find($id)->unidad_organica_id);

        // Una corrección con un puesto nuevo también lo agrega; el anterior queda en la lista.
        $this->patchJson("/api/v1/expedientes/{$persona->id}/vinculos-laborales/{$id}", ['nombre_puesto' => 'Técnico de biblioteca'])->assertOk();
        $this->assertSame(2, Puesto::query()->count());

        $puesto = Puesto::query()->firstOrFail();
        $this->getJson('/api/v1/catalogos/puestos')->assertForbidden();
        $this->patchJson("/api/v1/catalogos/puestos/{$puesto->id}", ['activo' => false])->assertForbidden();
        $this->postJson('/api/v1/catalogos/unidades-organicas', ['nombre' => 'Otra'])->assertForbidden();
    }

    public function test_admin_renames_and_deactivates_without_changing_links(): void
    {
        $this->actuarComo();
        $this->alta('71000041', ['nombre_puesto' => 'Analsta', 'unidad_organica_nombre' => 'Ofcina'])->assertCreated();
        $puesto = Puesto::query()->sole();
        $unidad = UnidadOrganica::query()->sole();

        $this->getJson('/api/v1/catalogos/puestos')->assertOk()->assertJsonPath('data.0.vinculos', 1);
        $this->patchJson("/api/v1/catalogos/puestos/{$puesto->id}", ['nombre' => 'Analista'])->assertOk()->assertJsonPath('data.nombre', 'Analista');
        $this->patchJson("/api/v1/catalogos/unidades-organicas/{$unidad->id}", ['nombre' => 'Oficina', 'codigo' => 'OF-01'])->assertOk();
        // El vínculo conserva el texto con el que se registró; la unidad es la misma (por id).
        $this->assertSame('Analsta', VinculoLaboral::query()->sole()->nombre_puesto);
        $this->assertSame(2, AuditoriaEvento::query()->whereIn('accion', ['catalogo.puesto.corregir', 'catalogo.unidad.corregir'])->count());

        $this->patchJson("/api/v1/catalogos/puestos/{$puesto->id}", ['activo' => false])->assertOk();
        $this->getJson('/api/v1/expediente-opciones')->assertJsonCount(0, 'data.puestos');
        // Desactivado: no se vuelve a crear ni se puede elegir en un vínculo nuevo.
        $this->alta('71000042', ['nombre_puesto' => 'analista'])->assertUnprocessable()->assertJsonValidationErrors('vinculo.nombre_puesto');
        $this->assertSame(1, Puesto::query()->count());
    }

    public function test_admin_cannot_create_duplicates_or_unit_cycles(): void
    {
        $this->actuarComo();
        $this->postJson('/api/v1/catalogos/puestos', ['nombre' => 'Asesor', 'tipo_personal' => 'administrativo'])->assertCreated();
        $this->postJson('/api/v1/catalogos/puestos', ['nombre' => 'ASESOR', 'tipo_personal' => 'administrativo'])->assertUnprocessable()->assertJsonValidationErrors('nombre');

        $padre = $this->postJson('/api/v1/catalogos/unidades-organicas', ['nombre' => 'Rectorado', 'tipo' => 'alta_direccion'])->assertCreated()->json('data.id');
        $hija = $this->postJson('/api/v1/catalogos/unidades-organicas', ['nombre' => 'Secretaría', 'unidad_padre_id' => $padre])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/catalogos/unidades-organicas', ['nombre' => 'rectorado'])->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->patchJson("/api/v1/catalogos/unidades-organicas/{$padre}", ['unidad_padre_id' => $hija])->assertUnprocessable()->assertJsonValidationErrors('unidad_padre_id');
    }

    public function test_an_old_link_keeps_its_deactivated_post_and_unit_when_corrected(): void
    {
        $this->actuarComo();
        $persona = $this->personaConLegajo();
        $id = $this->postJson("/api/v1/expedientes/{$persona->id}/vinculos-laborales", ['regimen_laboral' => 'plazo_determinado', 'fecha_inicio' => '2026-03-01',
            'nombre_puesto' => 'Secretario', 'unidad_organica_nombre' => 'Decanato'])->assertCreated()->json('data.id');
        Puesto::query()->update(['activo' => false]);
        UnidadOrganica::query()->update(['activo' => false]);

        $this->patchJson("/api/v1/expedientes/{$persona->id}/vinculos-laborales/{$id}", ['nombre_puesto' => 'SECRETARIO', 'unidad_organica_nombre' => 'Decanato',
            'numero_documento_vinculo' => 'RES-9'])->assertOk();
        $this->assertSame(['Secretario', 'RES-9'], [VinculoLaboral::query()->find($id)->nombre_puesto, VinculoLaboral::query()->find($id)->numero_documento_vinculo]);
    }
}
