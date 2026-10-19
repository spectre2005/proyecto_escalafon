<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\ExperienciaLaboral;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\Documentos\Folios;
use Database\Seeders\Seccion01CatalogoSeeder;
use Database\Seeders\Seccion03CatalogoSeeder;
use Database\Seeders\Seccion04CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/**
 * Carga documental desde los registros (fase 1): «Adjuntar documento» envía enlace_id al registro que acredita, los
 * documentos del vínculo (C a F) se agrupan con el vínculo elegido, un documento de un familiar guarda a ese familiar como
 * titular, y la subsanación de un tipo que ya no se ofrece se admite mientras la carga nueva sigue rechazada. Las
 * subsanaciones de los formularios combinados no duplican registros ni tiempo de servicio.
 */
class CargaDocumentalRegistrosTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararDemo();
        $this->actuarComo();
    }

    private function siguiente(Usuario $persona, int $seccion): int
    {
        return Folios::ultimo(Legajo::query()->where('usuario_id', $persona->id)->where('estado_legajo', 'activo')->firstOrFail(), $seccion) + 1;
    }

    private function tipoCodigo(string $codigo): TipoDocumento
    {
        return TipoDocumento::query()->where('codigo', $codigo)->where('activo', true)->firstOrFail();
    }

    public function test_link_documents_c_to_f_are_grouped_with_the_selected_link(): void
    {
        $persona = $this->personaDemo();
        $vinculo = VinculoLaboral::query()->where('usuario_id', $persona->id)->where('anulado', false)->whereNull('fecha_cese')->firstOrFail();
        foreach (['c', 'd', 'e', 'f'] as $literal) {
            $folio = $this->siguiente($persona, 2);
            $this->subir($persona, $this->metadatos($this->tipo02($literal), $folio, $folio, ['enlace_id' => $vinculo->id]))
                ->assertCreated()->assertJsonPath('data.enlazado', true)->assertJsonPath('data.vinculo_laboral_id', $vinculo->id);
        }
        $codigos = ['S02_C_RESOLUCION_DESIGNACION', 'S02_D_RESOLUCION_RENOVACION', 'S02_E_RESOLUCION_CARRERA', 'S02_F_CONTRATO'];
        $this->assertSame(4, DocumentoUsuario::query()->where('vinculo_laboral_id', $vinculo->id)
            ->whereHas('tipoDocumento', fn ($tipo) => $tipo->whereIn('codigo', $codigos))->count());

        // El vínculo de otra persona no se acepta como registro acreditado.
        $ajeno = VinculoLaboral::factory()->create();
        $multiple = collect(['c', 'd', 'e', 'f'])->map(fn (string $literal): TipoDocumento => $this->tipo02($literal))->firstOrFail(fn (TipoDocumento $tipo): bool => (bool) $tipo->permite_multiples);
        $folio = $this->siguiente($persona, 2);
        $this->subir($persona, $this->metadatos($multiple, $folio, $folio, ['enlace_id' => $ajeno->id]))->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
    }

    public function test_attaching_from_a_record_links_it_once(): void
    {
        $persona = $this->personaDemo();
        $vinculo = VinculoLaboral::query()->where('usuario_id', $persona->id)->where('anulado', false)->whereNull('fecha_cese')->firstOrFail();
        $adenda = $vinculo->vinculoAdendas()->whereNull('documento_id')->where('anulado', false)->first()
            ?? $vinculo->vinculoAdendas()->create(['numero' => 'AD-9', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-06-30']);
        $folio = $this->siguiente($persona, 2);
        $doc = $this->subir($persona, $this->metadatos($this->tipo02('g'), $folio, $folio, ['enlace_id' => $adenda->id, 'num_resolucion' => 'AD-9']))
            ->assertCreated()->assertJsonPath('data.enlazado', true)->json('data');
        $this->assertSame($doc['id'], $adenda->refresh()->documento_id);
        // Ya acreditada: un segundo documento no puede enlazarse a la misma adenda (el enlace se escribe una vez).
        $folio = $this->siguiente($persona, 2);
        $this->subir($persona, $this->metadatos($this->tipo02('g'), $folio, $folio, ['enlace_id' => $adenda->id]))->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->assertSame($doc['id'], $adenda->refresh()->documento_id);
    }

    public function test_a_family_member_document_keeps_the_family_member_as_holder(): void
    {
        $this->seed(Seccion01CatalogoSeeder::class);
        $persona = $this->personaDemo();
        $persona->update(['tiene_derechohabientes' => true]);
        $hijo = Familiar::factory()->create(['usuario_id' => $persona->id, 'parentesco' => 'hijo', 'apellidos_nombres' => 'Demo Hijo, Luis',
            'tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => '71112223', 'fecha_nacimiento' => now()->subYears(8)->toDateString(), 'documento_id' => null, 'anulado' => false]);
        $tipo = $this->tipoCodigo('S01_J_DNI_HIJOS_MENORES');
        $folio = $this->siguiente($persona, 1);
        $doc = $this->subir($persona, $this->metadatos($tipo, $folio, $folio, ['enlace_id' => $hijo->id, 'titular_nombre' => 'Demo Hijo, Luis',
            'titular_documento' => '71112223', 'relacion_titular' => 'hijo']))->assertCreated()->json('data');
        $guardado = DocumentoUsuario::query()->findOrFail($doc['id']);
        $this->assertSame(['Demo Hijo, Luis', '71112223', 'hijo'], [$guardado->titular_nombre, $guardado->titular_documento, $guardado->relacion_titular]);
        $this->assertSame($doc['id'], $hijo->refresh()->documento_id);
    }

    public function test_a_type_no_longer_required_accepts_corrections_but_not_new_uploads(): void
    {
        $this->seed(Seccion03CatalogoSeeder::class);
        $persona = $this->personaDemo();
        $vigentes = VinculoLaboral::query()->where('usuario_id', $persona->id)->where('anulado', false)->whereNull('fecha_cese');
        $vigentes->update(['exige_colegiatura' => true]);
        $tipo = $this->tipoCodigo('FORM_COLEGIATURA');
        $subir = fn (array $cambios = []): TestResponse => $this->post('/api/v1/expedientes/'.$persona->id.'/seccion-03/documentos', [
            'tipo_documento_id' => $tipo->id, 'nombre' => 'Constancia de habilitación', 'emisor' => 'Colegio de Abogados', 'fecha_emision' => '2025-05-02',
            'folio_inicio' => $this->siguiente($persona, 3), 'folio_fin' => $this->siguiente($persona, 3), 'modalidad_presentacion' => 'copia_simple',
            'numero_colegiatura' => 'CAL-123', 'vigente_hasta' => '2026-12-31', 'pdf' => $this->pdf(paginas: 1), ...$cambios,
        ], ['Accept' => 'application/json']);
        $original = $subir()->assertCreated()->json('data.id');

        // La subsanación propone los datos del registro: la lectura de la sección 03 los devuelve con el documento.
        $item = collect($this->getJson('/api/v1/expedientes/'.$persona->id.'/seccion-03')->assertOk()->json('data.items'))->firstWhere('codigo', 'FORM_COLEGIATURA');
        $this->assertSame(['numero_colegiatura' => 'CAL-123', 'vigente_hasta' => '2026-12-31'], collect($item['documentos'])->firstWhere('id', $original)['datos']);

        $vigentes->update(['exige_colegiatura' => false]);
        // Una carga nueva del tipo que ya no corresponde sigue rechazada.
        $subir()->assertUnprocessable()->assertJsonValidationErrors('tipo_documento_id');
        // Subsanar el documento existente sí se admite.
        $correccion = $subir(['subsana_a_documento_id' => $original, 'numero_colegiatura' => 'CAL-124'])->assertCreated()->json('data.id');
        $this->assertSame($original, DocumentoUsuario::query()->findOrFail($correccion)->subsana_a_documento_id);
    }

    public function test_correcting_an_experience_certificate_does_not_double_the_time(): void
    {
        $this->seed(Seccion04CatalogoSeeder::class);
        $persona = $this->personaDemo();
        $tipo = $this->tipoCodigo('EXP_CERTIFICADO_TRABAJO');
        $subir = fn (array $cambios = []): TestResponse => $this->post('/api/v1/expedientes/'.$persona->id.'/seccion-04/documentos', [
            'tipo_documento_id' => $tipo->id, 'nombre' => 'Certificado de trabajo', 'emisor' => 'Municipalidad Demo', 'fecha_emision' => '2024-01-15',
            'folio_inicio' => $this->siguiente($persona, 4), 'folio_fin' => $this->siguiente($persona, 4), 'modalidad_presentacion' => 'copia_simple',
            'sector' => 'publico', 'cargo' => 'Asistente', 'fecha_inicio' => '2022-01-01', 'fecha_fin' => '2023-12-31', 'tipo_vinculo' => 'planilla',
            'es_ejercicio_profesional' => '1', 'es_docencia_universitaria' => '0', 'pdf' => $this->pdf(paginas: 1), ...$cambios,
        ], ['Accept' => 'application/json']);
        $original = $subir()->assertCreated()->json('data.id');
        $correccion = $subir(['subsana_a_documento_id' => $original, 'cargo' => 'Asistente administrativo'])->assertCreated()->json('data.id');

        // Cada documento conserva su registro (no se borra el histórico), pero solo cuenta el vigente.
        $this->assertSame(2, ExperienciaLaboral::query()->where('usuario_id', $persona->id)->count());
        $periodos = $this->getJson('/api/v1/expedientes/'.$persona->id.'/seccion-04')->assertOk()->json('data.tiempo.periodos');
        $this->assertCount(1, $periodos);
        $this->assertSame($correccion, $periodos[0]['documento_id']);
    }
}
