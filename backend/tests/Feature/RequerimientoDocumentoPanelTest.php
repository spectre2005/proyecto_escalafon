<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAdministrator;
use App\Models\RequerimientoDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Database\Seeders\RequerimientoDocumentoSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequerimientoDocumentoPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Authenticate::class,
            RequireAdministrator::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_index_includes_related_person_type_and_filter_options(): void
    {
        $person = Usuario::factory()->create([
            'nombres' => 'Persona Ficticia',
            'apellido_paterno' => 'Prueba',
        ]);
        $requester = Usuario::factory()->create();
        $documentType = TipoDocumento::factory()->create(['nombre' => 'Constancia ficticia']);

        $requirement = RequerimientoDocumento::factory()->create([
            'usuario_id' => $person->id,
            'tipo_documento_id' => $documentType->id,
            'solicitado_por' => $requester->id,
            'origen' => 'legajo',
            'descripcion' => 'Presentar constancia ficticia',
            'estado' => 'abierto',
        ]);

        $this->getJson('/api/v1/requerimientos-documentos')
            ->assertOk()
            ->assertJsonFragment(['id' => $requirement->id])
            ->assertJsonFragment(['nombre' => 'Constancia ficticia'])
            ->assertJsonFragment(['nombres' => 'Persona Ficticia'])
            ->assertJsonPath('filters.estados.0', 'abierto')
            ->assertJsonPath('filters.origenes.0', 'legajo')
            ->assertJsonStructure(['data', 'links', 'meta', 'filters']);
    }

    public function test_index_filters_by_search_state_and_origin(): void
    {
        $person = Usuario::factory()->create([
            'nombres' => 'Persona Buscada',
            'apellido_paterno' => 'Ficticia',
        ]);
        $requester = Usuario::factory()->create();

        $matching = RequerimientoDocumento::factory()->create([
            'usuario_id' => $person->id,
            'solicitado_por' => $requester->id,
            'origen' => 'legajo',
            'descripcion' => 'Documento para revisión',
            'estado' => 'abierto',
        ]);

        RequerimientoDocumento::factory()->create([
            'usuario_id' => $person->id,
            'solicitado_por' => $requester->id,
            'origen' => 'archivo',
            'descripcion' => 'Otro documento ficticio',
            'estado' => 'abierto',
        ]);

        $this->getJson('/api/v1/requerimientos-documentos?q=buscada&estado=abierto&origen=legajo')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_index_paginates_results_and_preserves_the_filtered_total(): void
    {
        $person = Usuario::factory()->create();
        $requester = Usuario::factory()->create();

        RequerimientoDocumento::factory()->count(21)->create([
            'usuario_id' => $person->id,
            'solicitado_por' => $requester->id,
            'origen' => 'legajo',
            'estado' => 'abierto',
        ]);

        $this->getJson('/api/v1/requerimientos-documentos?estado=abierto&origen=legajo')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 21)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('links.next', fn (string $url): bool => str_contains($url, 'estado=abierto') && str_contains($url, 'origen=legajo'));

        $this->getJson('/api/v1/requerimientos-documentos?estado=abierto&origen=legajo&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 21)
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_index_rejects_invalid_filter_values(): void
    {
        $this->getJson('/api/v1/requerimientos-documentos?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');

        $this->getJson('/api/v1/requerimientos-documentos?q='.str_repeat('a', 121))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_api_validates_and_persists_a_requirement_using_existing_fields(): void
    {
        $person = Usuario::factory()->create();
        $requester = Usuario::factory()->create();

        $payload = [
            'usuario_id' => $person->id,
            'origen' => 'legajo',
            'descripcion' => 'Solicitud de documento ficticia',
            'fecha_requerimiento' => '2026-09-01',
            'plazo_dias_habiles' => 5,
            'fecha_limite' => '2026-09-08',
            'estado' => 'abierto',
            'solicitado_por' => $requester->id,
        ];

        $this->postJson('/api/v1/requerimientos-documentos', $payload)
            ->assertCreated()
            ->assertJsonPath('data.usuario_id', $person->id)
            ->assertJsonPath('data.descripcion', 'Solicitud de documento ficticia');

        $this->assertDatabaseHas('requerimientos_documentos', [
            'usuario_id' => $person->id,
            'solicitado_por' => $requester->id,
            'descripcion' => 'Solicitud de documento ficticia',
        ]);

        $this->postJson('/api/v1/requerimientos-documentos', ['usuario_id' => $person->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'origen',
                'descripcion',
                'fecha_requerimiento',
                'plazo_dias_habiles',
                'fecha_limite',
                'solicitado_por',
            ]);
    }

    public function test_fictional_seeder_creates_ten_repeatable_requirements_for_demo_people(): void
    {
        $this->seed(RequerimientoDocumentoSeeder::class);

        $this->assertDatabaseCount('requerimientos_documentos', 10);

        $this->seed(RequerimientoDocumentoSeeder::class);

        $this->assertDatabaseCount('requerimientos_documentos', 10);
        $this->getJson('/api/v1/requerimientos-documentos')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonFragment(['descripcion' => 'Ejemplo ficticio de requerimiento 01'])
            ->assertJsonFragment(['nombres' => 'Persona Demo 01']);
    }
}
