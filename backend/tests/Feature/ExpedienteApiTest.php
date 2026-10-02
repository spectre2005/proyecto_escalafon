<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAdministrator;
use App\Models\ArchivoDocumento;
use App\Models\BitacoraAccesoLegajo;
use App\Models\Capacitacion;
use App\Models\CompromisoEntregaOriginal;
use App\Models\DocumentoUsuario;
use App\Models\EvaluacionDesempeno;
use App\Models\Legajo;
use App\Models\MovimientoCustodiaLegajo;
use App\Models\ObservacionDocumento;
use App\Models\PerfilDocente;
use App\Models\ProcesoFiscalizacion;
use App\Models\SolicitudFiscalizacion;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpedienteApiTest extends TestCase
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

    public function test_directory_searches_people_and_legajo_numbers_and_paginates(): void
    {
        $person = Usuario::factory()->create(['nombres' => 'Persona Ficticia', 'apellido_paterno' => 'Ejemplo']);
        Legajo::factory()->create(['usuario_id' => $person->id, 'numero_legajo' => 'DEMO-001']);
        Usuario::factory()->count(21)->create();
        $this->getJson('/api/v1/expedientes')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('total', 22);
        $this->getJson('/api/v1/expedientes?q=demo-001')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $person->id);
        $this->getJson('/api/v1/expedientes?q=Persona%20Ficticia%20Ejemplo')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/expedientes?legajo=sin')->assertOk()->assertJsonPath('total', 21);
    }

    public function test_person_without_legajo_has_general_data_and_empty_sections(): void
    {
        $person = Usuario::factory()->create();
        $response = $this->getJson('/api/v1/expedientes/'.$person->id)->assertOk()
            ->assertJsonCount(0, 'data.legajos')->assertJsonMissingPath('data.usuario.values.password');
        foreach ($response->json('data.sections') as $section) {
            $this->getJson('/api/v1/expedientes/'.$person->id.'/'.$section['key'])
                ->assertOk()->assertJsonPath('total', 0);
        }
    }

    public function test_documents_are_isolated_by_person_and_selected_legajo_and_hide_storage_details(): void
    {
        $person = Usuario::factory()->create();
        $first = Legajo::factory()->create(['usuario_id' => $person->id]);
        $second = Legajo::factory()->create(['usuario_id' => $person->id]);
        $file = ArchivoDocumento::factory()->create();
        $document = DocumentoUsuario::factory()->create(['usuario_id' => $person->id, 'legajo_id' => $first->id, 'archivo_id' => $file->id]);
        DocumentoUsuario::factory()->create(['usuario_id' => $person->id, 'legajo_id' => $second->id]);
        DocumentoUsuario::factory()->create();
        $base = '/api/v1/expedientes/'.$person->id;
        $this->getJson($base.'/documentos-usuario')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($base.'/documentos-usuario?legajo_id='.$first->id)->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath('data.0.values.archivo_id', $file->nombre_original);
        $this->getJson($base.'/documentos-usuario?seccion_id='.$document->seccion_legajo_id)->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $document->id);
        $this->getJson($base.'/documentos-usuario?estado=no_existe')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($base.'/archivos-documento?legajo_id='.$first->id)->assertOk()
            ->assertJsonPath('data.0.values.nombre_original', $file->nombre_original)
            ->assertJsonMissingPath('data.0.values.storage_path')->assertJsonMissingPath('data.0.values.hash_sha256');
        $foreign = Legajo::factory()->create();
        $this->getJson($base.'/documentos-usuario?legajo_id='.$foreign->id)->assertNotFound();
    }

    public function test_observations_follow_document_owner_and_access_log_uses_legajo_not_actor(): void
    {
        $person = Usuario::factory()->create();
        $legajo = Legajo::factory()->create(['usuario_id' => $person->id]);
        $document = DocumentoUsuario::factory()->create(['usuario_id' => $person->id, 'legajo_id' => $legajo->id]);
        $observation = ObservacionDocumento::factory()->create(['documento_id' => $document->id]);
        ObservacionDocumento::factory()->create();
        $access = BitacoraAccesoLegajo::factory()->create(['legajo_id' => $legajo->id, 'documento_id' => $document->id]);
        $base = '/api/v1/expedientes/'.$person->id;
        $this->getJson($base.'/observaciones-documento')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $observation->id);
        $this->getJson($base.'/bitacora-accesos-legajo')->assertOk()->assertJsonPath('data.0.id', $access->id)
            ->assertJsonMissingPath('data.0.values.ip_origen');
    }

    public function test_personal_background_includes_records_without_document_and_marks_annulled_records(): void
    {
        $person = Usuario::factory()->create();
        Capacitacion::factory()->create(['usuario_id' => $person->id, 'documento_id' => null, 'anulado' => true]);
        EvaluacionDesempeno::factory()->create(['usuario_id' => $person->id, 'documento_id' => null]);
        Capacitacion::factory()->create();
        $base = '/api/v1/expedientes/'.$person->id;
        $this->getJson($base.'/capacitaciones')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.values.anulado', true);
        $this->getJson($base.'/evaluaciones-desempeno')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_files_include_attachments_to_commitments_and_custody_without_duplicates(): void
    {
        $person = Usuario::factory()->create();
        $legajo = Legajo::factory()->create(['usuario_id' => $person->id]);
        $file = ArchivoDocumento::factory()->create();
        CompromisoEntregaOriginal::factory()->create(['usuario_id' => $person->id, 'legajo_id' => $legajo->id, 'archivo_id' => $file->id]);
        MovimientoCustodiaLegajo::factory()->create(['legajo_id' => $legajo->id, 'sustento_archivo_id' => $file->id]);
        $this->getJson('/api/v1/expedientes/'.$person->id.'/archivos-documento')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $file->id);
    }

    public function test_nested_labor_and_fiscalization_records_are_scoped_to_the_person(): void
    {
        $person = Usuario::factory()->create();
        $legajo = Legajo::factory()->create(['usuario_id' => $person->id]);
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $person->id]);
        $perfil = PerfilDocente::factory()->create(['vinculo_laboral_id' => $vinculo->id]);
        PerfilDocente::factory()->create();
        $proceso = ProcesoFiscalizacion::factory()->create(['usuario_id' => $person->id, 'legajo_id' => $legajo->id]);
        $solicitud = SolicitudFiscalizacion::factory()->create(['proceso_fiscalizacion_id' => $proceso->id]);
        SolicitudFiscalizacion::factory()->create();
        $base = '/api/v1/expedientes/'.$person->id;
        $this->getJson($base.'/perfil-docente')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $perfil->id);
        $this->getJson($base.'/solicitudes-fiscalizacion')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $solicitud->id);
    }

    public function test_invalid_filters_and_unknown_resources_are_rejected(): void
    {
        $person = Usuario::factory()->create();
        $this->getJson('/api/v1/expedientes?tipo=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/expedientes/'.$person->id.'/unknown')->assertNotFound();
        $this->getJson('/api/v1/expedientes/'.$person->id.'/documentos-usuario?legajo_id=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/expedientes/'.$person->id.'/documentos-usuario?page=-1')->assertUnprocessable();
    }

    public function test_expedientes_are_unavailable_in_production(): void
    {
        $person = Usuario::factory()->create();
        $this->app->instance('env', 'production');
        $this->getJson('/api/v1/expedientes')->assertForbidden();
        $this->getJson('/api/v1/expedientes/'.$person->id)->assertForbidden();
        $this->getJson('/api/v1/expedientes/'.$person->id.'/capacitaciones')->assertForbidden();
    }
}
