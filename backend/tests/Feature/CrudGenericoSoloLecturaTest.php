<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\GoceVacacional;
use App\Models\Induccion;
use App\Models\LicenciaPermiso;
use App\Models\PerfilAdministrativo;
use App\Models\PerfilDocente;
use App\Models\PeriodoPrueba;
use App\Models\PeriodoVacacional;
use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/**
 * CRUD genérico de la sección 02 y de los documentos: solo de lectura. Los registros de la 02 se crean, corrigen y
 * anulan solo desde Seccion02Controller; documentos y archivos se crean solo por la carga del legajo.
 */
class CrudGenericoSoloLecturaTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private const SECCION_02 = [
        'vinculos-laborales' => VinculoLaboral::class, 'vinculo-adendas' => VinculoAdenda::class, 'inducciones' => Induccion::class,
        'periodos-prueba' => PeriodoPrueba::class, 'perfil-docente' => PerfilDocente::class, 'perfil-administrativo' => PerfilAdministrativo::class,
        // Sección 05: se registran y anulan solo desde Seccion05Controller.
        'licencias-permisos' => LicenciaPermiso::class, 'periodos-vacacionales' => PeriodoVacacional::class, 'goces-vacacionales' => GoceVacacional::class,
    ];

    private const DOCUMENTOS = ['documentos-usuario' => DocumentoUsuario::class, 'archivos-documento' => ArchivoDocumento::class];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararDemo();
    }

    /** Puede ver y editar legajos, pero no administra el sistema (el CRUD genérico exige sistema.administrar). */
    private function actuarSinPermiso(): void
    {
        $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
    }

    public function test_section_02_records_are_read_only_in_the_generic_crud(): void
    {
        foreach (self::SECCION_02 as $recurso => $clase) {
            $registro = $clase::factory()->create();
            $antes = $registro->refresh()->getAttributes();
            $total = $clase::query()->count();
            $payload = array_diff_key($clase::factory()->make()->getAttributes(), ['id' => true]);
            $url = '/api/v1/'.$recurso.'/'.$registro->getKey();

            $this->actuarComo();
            $this->getJson('/api/v1/'.$recurso)->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.id', $registro->getKey());
            $this->postJson('/api/v1/'.$recurso, $payload)->assertStatus(405);
            $this->patchJson($url, ['anulado' => false])->assertStatus(405);
            $this->putJson($url, [])->assertStatus(405);
            $this->deleteJson($url)->assertStatus(405);

            $this->actuarSinPermiso();
            $this->getJson('/api/v1/'.$recurso)->assertForbidden();
            $this->postJson('/api/v1/'.$recurso, $payload)->assertStatus(405);
            $this->patchJson($url, [])->assertStatus(405);
            $this->deleteJson($url)->assertStatus(405);

            $this->assertSame($total, $clase::query()->count(), $recurso);
            $this->assertSame($antes, $registro->refresh()->getAttributes(), $recurso);
        }
    }

    public function test_documents_and_files_are_read_only_in_the_generic_crud(): void
    {
        $documento = DocumentoUsuario::factory()->create();
        $archivo = ArchivoDocumento::factory()->create();
        foreach (['documentos-usuario' => $documento, 'archivos-documento' => $archivo] as $recurso => $registro) {
            $url = '/api/v1/'.$recurso;
            $payload = array_diff_key($registro->getAttributes(), ['id' => true]);

            $this->actuarComo();
            $this->getJson($url)->assertOk();
            $this->getJson($url.'/'.$registro->getKey())->assertOk();
            $this->postJson($url, $payload)->assertStatus(405);
            $this->patchJson($url.'/'.$registro->getKey(), $payload)->assertStatus(405);
            $this->deleteJson($url.'/'.$registro->getKey())->assertStatus(405);

            $this->actuarSinPermiso();
            $this->postJson($url, $payload)->assertStatus(405);
            $this->deleteJson($url.'/'.$registro->getKey())->assertStatus(405);
        }
        $this->assertSame(1, DocumentoUsuario::query()->count());
        $this->assertSame(1, ArchivoDocumento::query()->count());
        $this->assertModelExists($documento);
        $this->assertModelExists($archivo);
    }

    public function test_section_02_upload_reception_and_annulment_still_work(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo();
        $base = '/api/v1/expedientes/'.$persona->id;
        $id = $this->subir($persona, $this->metadatos($this->tipo02('a'), 1, 1))->assertCreated()->json('data.id');
        $this->postJson($base.'/documentos/'.$id.'/recepcionar')->assertOk();
        $this->get($base.'/documentos/'.$id.'/archivo')->assertOk();
        $this->assertNotNull(DocumentoUsuario::query()->findOrFail($id)->fecha_recepcion);
        // Lo que el CRUD genérico ya no permite se hace desde la sección 02.
        $vinculo = VinculoLaboral::query()->where('usuario_id', $persona->id)->whereNull('fecha_cese')->firstOrFail();
        $this->postJson($base.'/inducciones', ['vinculo_laboral_id' => $vinculo->id, 'tipo' => 'general', 'fecha' => today()->toDateString()])->assertCreated();
        $this->patchJson($base.'/vinculos-laborales/'.$vinculo->id, ['nombre_puesto' => 'Puesto corregido'])->assertOk();
        $this->postJson($base.'/seccion-02/vinculos-laborales/'.$vinculo->id.'/anular', ['motivo_anulacion' => 'Registrado por error'])->assertOk();
        $this->assertTrue($vinculo->refresh()->anulado);
    }

    public function test_section_01_documents_still_upload_and_receive_through_the_upload_service(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo();
        SeccionLegajo::query()->firstOrCreate(['id' => 1], ['nombre' => 'Información personal y familiar', 'orden' => 1]);
        $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => 1, 'subseccion_legajo_id' => null, 'activo' => true]);
        $id = $this->subir($persona, $this->metadatos($tipo, 1, 1))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/expedientes/'.$persona->id.'/documentos/'.$id.'/recepcionar')->assertOk();
        $this->getJson('/api/v1/documentos-usuario/'.$id)->assertOk()->assertJsonPath('data.seccion_legajo_id', 1);
    }
}
