<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\Induccion;
use App\Models\LegajoSeccion;
use App\Models\ParametroSistema;
use App\Models\PeriodoPrueba;
use App\Models\RegimenNormativo;
use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class DocumentoLegajoApiTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararDemo();
    }

    public function test_valid_pdf_creates_file_document_and_history(): void
    {
        $autor = $this->actuarComo();
        $persona = $this->personaDemo();
        $tipo = $this->tipo02('a');

        $id = $this->subir($persona, $this->metadatos($tipo, 1, 2))->assertCreated()
            ->assertJsonPath('data.estado', 'aceptado')->assertJsonPath('data.formulario_datos', null)->json('data.id');

        $documento = DocumentoUsuario::query()->findOrFail($id);
        $archivo = ArchivoDocumento::query()->findOrFail($documento->archivo_id);
        $this->assertSame([$persona->id, 2, 1, 2, $autor->id], [$documento->usuario_id, $documento->seccion_legajo_id, $documento->folio_inicio, $documento->folio_fin, $documento->created_by]);
        $this->assertSame('servidor', $documento->relacion_titular);
        $this->assertSame([2, 'pendiente', 'application/pdf', $autor->id, false], [$archivo->num_paginas, $archivo->estado_antivirus, $archivo->mime_type, $archivo->subido_por, $archivo->cifrado]);
        $this->assertSame('documento.pdf', $archivo->nombre_original);
        // Disco privado, nombre interno aleatorio y hash del contenido real.
        $disco = Storage::disk(config('legajo.disco_documentos'));
        $disco->assertExists($archivo->storage_path);
        $this->assertStringNotContainsString('documento.pdf', $archivo->storage_path);
        $this->assertSame(hash('sha256', $disco->get($archivo->storage_path)), $archivo->hash_sha256);
        $this->assertNull(config('filesystems.disks.documentos.url'));
        $this->assertDatabaseHas('historial_estados_documento', ['documento_usuario_id' => $id, 'estado_anterior' => null, 'estado_nuevo' => 'aceptado', 'cambiado_por' => $autor->id]);
    }

    public function test_rejects_files_that_are_not_pdf_even_with_pdf_extension(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo();
        $tipo = $this->tipo02('a');

        $this->subir($persona, $this->metadatos($tipo, 1, 1, ['pdf' => UploadedFile::fake()->createWithContent('falso.pdf', 'Esto es texto plano, no un PDF.')]))
            ->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $this->subir($persona, $this->metadatos($tipo, 1, 1, ['pdf' => UploadedFile::fake()->createWithContent('foto.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='))]))
            ->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $this->assertDatabaseCount('documentos_usuario', 0);
        $this->assertDatabaseCount('archivos_documento', 0);
        $this->assertSame([], Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_rejects_files_over_the_configured_size(): void
    {
        $this->actuarComo();
        ParametroSistema::query()->create(['clave' => 'tamano_maximo_pdf_kb', 'valor' => '1', 'tipo_dato' => 'int']);

        $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('a'), 1, 1, ['pdf' => $this->pdf('grande.pdf', 3000)]))
            ->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $this->assertDatabaseCount('documentos_usuario', 0);
    }

    public function test_rejects_type_of_another_section_and_person_without_legajo(): void
    {
        $this->actuarComo();
        $otraSeccion = TipoDocumento::factory()->create(['seccion_legajo_id' => SeccionLegajo::query()->findOrFail(3)->id]);

        $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('a'), 1, 1, ['tipo_documento_id' => $otraSeccion->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('tipo_documento_id');
        $this->subir(Usuario::factory()->create(), $this->metadatos($this->tipo02('a'), 1, 1))->assertConflict();
        $this->assertDatabaseCount('documentos_usuario', 0);
    }

    public function test_validates_folios_and_resolution_number(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo();

        $this->subir($persona, $this->metadatos($this->tipo02('a'), 3, 2))->assertUnprocessable()->assertJsonValidationErrors('folio_fin');
        $this->subir($persona, $this->metadatos($this->tipo02('c'), 1, 1, ['num_resolucion' => null]))->assertUnprocessable()->assertJsonValidationErrors('num_resolucion');
        // f) Contrato no exige N.° de resolución.
        $this->subir($persona, $this->metadatos($this->tipo02('f'), 1, 1, ['num_resolucion' => null]))->assertCreated();
        $this->subir($persona, $this->metadatos($this->tipo02('a'), 2, 3, ['emisor' => '', 'titular_documento' => '', 'modalidad_presentacion' => 'fotocopia', 'relacion_titular' => 'tio']))
            ->assertUnprocessable()->assertJsonValidationErrors(['emisor', 'titular_documento', 'modalidad_presentacion', 'relacion_titular']);
        $this->subir($persona, [...$this->metadatos($this->tipo02('a'), 2, 3), 'estado' => 'verificado'])->assertUnprocessable()->assertJsonValidationErrors('estado');
    }

    public function test_folios_are_correlative_without_overlaps_or_gaps(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo();
        $legajo = $persona->legajos()->firstOrFail();

        $this->getJson('/api/v1/expedientes/'.$persona->id.'/documentos/folio-sugerido?seccion=2')->assertOk()
            ->assertJsonPath('data.ultimo_folio', 0)->assertJsonPath('data.folio_sugerido', 1);
        $this->subir($persona, $this->metadatos($this->tipo02('a'), 1, 3))->assertCreated();
        $seccion = LegajoSeccion::query()->where('legajo_id', $legajo->id)->where('seccion_legajo_id', 2)->firstOrFail();
        $this->assertSame(3, $seccion->ultimo_folio);
        $this->assertNotNull($seccion->ultima_actualizacion);

        $this->subir($persona, $this->metadatos($this->tipo02('b'), 2, 4))->assertUnprocessable()->assertJsonValidationErrors('folio_inicio');
        $this->subir($persona, $this->metadatos($this->tipo02('b'), 6, 6))->assertUnprocessable()->assertJsonValidationErrors('folio_inicio');
        $this->subir($persona, $this->metadatos($this->tipo02('b'), 4, 5))->assertCreated();
        $this->assertSame(5, $seccion->refresh()->ultimo_folio);
        $this->getJson('/api/v1/expedientes/'.$persona->id.'/documentos/folio-sugerido?seccion=2')->assertJsonPath('data.folio_sugerido', 6);

        // Dos cargas seguidas no repiten folios: la segunda con el mismo folio sugerido se rechaza.
        $this->subir($persona, $this->metadatos($this->tipo02('i'), 6, 6))->assertCreated();
        $this->subir($persona, $this->metadatos($this->tipo02('j'), 6, 6))->assertUnprocessable()->assertJsonValidationErrors('folio_inicio');
        $folios = DocumentoUsuario::query()->where('legajo_id', $legajo->id)->orderBy('folio_inicio')->get(['folio_inicio', 'folio_fin'])->toArray();
        $this->assertSame([['folio_inicio' => 1, 'folio_fin' => 3], ['folio_inicio' => 4, 'folio_fin' => 5], ['folio_inicio' => 6, 'folio_fin' => 6]], $folios);
    }

    public function test_documents_are_never_replaced_or_deleted_only_amended(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo();
        $tipo = $this->tipo02('a');
        $primero = $this->subir($persona, $this->metadatos($tipo, 1, 1))->assertCreated()->json('data.id');
        $original = DocumentoUsuario::query()->findOrFail($primero)->getAttributes();

        // a) admite un solo documento: el segundo solo entra subsanando al anterior.
        $this->subir($persona, $this->metadatos($tipo, 2, 2))->assertUnprocessable()->assertJsonValidationErrors('subsana_a_documento_id');
        $segundo = $this->subir($persona, $this->metadatos($tipo, 2, 2, ['subsana_a_documento_id' => $primero]))->assertCreated()
            ->assertJsonPath('data.subsana_a_documento_id', $primero)->json('data.id');
        // Un documento ya subsanado no se subsana otra vez; se subsana el vigente.
        $this->subir($persona, $this->metadatos($tipo, 3, 3, ['subsana_a_documento_id' => $primero]))->assertUnprocessable()->assertJsonValidationErrors('subsana_a_documento_id');
        $this->subir($persona, $this->metadatos($tipo, 3, 3, ['subsana_a_documento_id' => $segundo]))->assertCreated();

        $this->assertSame($original, DocumentoUsuario::query()->findOrFail($primero)->getAttributes());
        Storage::disk(config('legajo.disco_documentos'))->assertExists(ArchivoDocumento::query()->findOrFail($original['archivo_id'])->storage_path);
        // El CRUD genérico no edita ni elimina documentos ni archivos.
        $this->deleteJson('/api/v1/documentos-usuario/'.$primero)->assertMethodNotAllowed();
        $this->patchJson('/api/v1/documentos-usuario/'.$primero, ['estado' => 'verificado'])->assertMethodNotAllowed();
        $this->deleteJson('/api/v1/archivos-documento/'.$original['archivo_id'])->assertMethodNotAllowed();
        $this->putJson('/api/v1/archivos-documento/'.$original['archivo_id'], ['storage_path' => 'x'])->assertMethodNotAllowed();
        $this->assertDatabaseCount('documentos_usuario', 3);
    }

    public function test_types_with_form_link_to_their_record(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo();
        $url = '/api/v1/expedientes/'.$persona->id;
        $vinculo = VinculoLaboral::query()->where('usuario_id', $persona->id)->whereNull('fecha_cese')->firstOrFail();
        $adenda = VinculoAdenda::query()->where('vinculo_laboral_id', $vinculo->id)->firstOrFail();
        $induccion = Induccion::query()->where('usuario_id', $persona->id)->where('anulado', false)->firstOrFail();
        $periodo = PeriodoPrueba::query()->where('usuario_id', $persona->id)->firstOrFail();

        // c) a f) con un vínculo existente: el documento apunta al vínculo.
        $c = $this->subir($persona, $this->metadatos($this->tipo02('c'), 1, 1, ['enlace_id' => $vinculo->id]))->assertCreated()
            ->assertJsonPath('data.formulario_datos', 'vinculos_laborales')->assertJsonPath('data.enlazado', true)->json('data.id');
        $this->assertSame($vinculo->id, DocumentoUsuario::query()->findOrFail($c)->vinculo_laboral_id);
        foreach (['d' => 2, 'e' => 3] as $literal => $folio) {
            $id = $this->subir($persona, $this->metadatos($this->tipo02($literal), $folio, $folio, ['enlace_id' => $vinculo->id]))->assertCreated()->json('data.id');
            $this->assertSame($vinculo->id, DocumentoUsuario::query()->findOrFail($id)->vinculo_laboral_id);
        }
        // f) sin vínculo previo: se abre el formulario y el vínculo nuevo queda enlazado al documento.
        $f = $this->subir($persona, $this->metadatos($this->tipo02('f'), 4, 4))->assertCreated()->assertJsonPath('data.enlazado', false)
            ->assertJsonPath('data.formulario_datos', 'vinculos_laborales')->json('data.id');
        $nuevo = $this->postJson($url.'/vinculos-laborales', ['modalidad_ingreso' => 'concurso_publico', 'proceso_seleccion' => 'CONCURSO-001-2026', 'anio_proceso' => 2026, 'numero_contrato' => 'C-001', 'fecha_contrato' => '2025-12-30', 'tipo_personal' => 'administrativo', 'regimen_laboral' => 'plazo_determinado', 'regimen_normativo_id' => RegimenNormativo::factory()->create(['aplica_a' => 'administrativo'])->id,
            'nombre_puesto' => 'Analista', 'fecha_inicio' => today()->toDateString(), 'documento_id' => $f])->assertCreated()->json('data.id');
        $this->assertSame($nuevo, DocumentoUsuario::query()->findOrFail($f)->vinculo_laboral_id);

        // g) adenda, h) inducción y m) período: el registro guarda documento_id.
        $g = $this->subir($persona, $this->metadatos($this->tipo02('g'), 5, 5, ['enlace_id' => $adenda->id]))->assertCreated()->json('data.id');
        $this->assertSame($g, $adenda->refresh()->documento_id);
        $g2 = $this->subir($persona, $this->metadatos($this->tipo02('g'), 6, 6))->assertCreated()->assertJsonPath('data.formulario_datos', 'vinculo_adendas')->json('data.id');
        $this->postJson($url.'/vinculo-adendas', ['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => today()->addDays(21)->toDateString(), 'fecha_fin' => today()->addDays(60)->toDateString(), 'documento_id' => $g2])
            ->assertCreated()->assertJsonPath('data.documento_id', $g2);
        $h = $this->subir($persona, $this->metadatos($this->tipo02('h'), 7, 7, ['enlace_id' => $induccion->id]))->assertCreated()->json('data.id');
        $this->assertSame($h, $induccion->refresh()->documento_id);
        $m = $this->subir($persona, $this->metadatos($this->tipo02('m'), 8, 8, ['enlace_id' => $periodo->id]))->assertCreated()->json('data.id');
        $this->assertSame($m, $periodo->refresh()->documento_id);

        // Un registro que ya tiene documento no se reenlaza; un documento de otro tipo no abre otro formulario.
        $this->subir($persona, $this->metadatos($this->tipo02('h'), 9, 9, ['enlace_id' => $induccion->id]))->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->postJson($url.'/inducciones', ['vinculo_laboral_id' => $vinculo->id, 'tipo' => 'general', 'fecha' => today()->toDateString(), 'documento_id' => $g])
            ->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $this->postJson($url.'/vinculos-laborales', ['modalidad_ingreso' => 'concurso_publico', 'proceso_seleccion' => 'CONCURSO-001-2026', 'anio_proceso' => 2026, 'numero_contrato' => 'C-001', 'fecha_contrato' => '2025-12-30', 'tipo_personal' => 'administrativo', 'regimen_laboral' => 'plazo_determinado', 'regimen_normativo_id' => RegimenNormativo::factory()->create(['aplica_a' => 'administrativo'])->id,
            'nombre_puesto' => 'Otro', 'fecha_inicio' => today()->toDateString(), 'documento_id' => $f])->assertUnprocessable()->assertJsonValidationErrors('documento_id');
    }

    public function test_types_without_form_do_not_open_or_link_any(): void
    {
        $this->actuarComo();
        $persona = $this->personaDemo();
        $vinculo = VinculoLaboral::query()->where('usuario_id', $persona->id)->firstOrFail();

        foreach (['a', 'b', 'i', 'j', 'k', 'l'] as $folio => $literal) {
            $this->subir($persona, $this->metadatos($this->tipo02($literal), $folio + 1, $folio + 1))->assertCreated()
                ->assertJsonPath('data.formulario_datos', null)->assertJsonPath('data.vinculo_laboral_id', null);
        }
        $this->subir($persona, $this->metadatos($this->tipo02('i'), 7, 7, ['enlace_id' => $vinculo->id]))->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
    }

    public function test_upload_requires_documento_subir(): void
    {
        config(['permisos.roles.SOLO_EDITA' => ['legajo.ver', 'legajo.editar']]);
        $this->actuarComo(null, 'SOLO_EDITA');
        $persona = $this->personaDemo();

        $this->subir($persona, $this->metadatos($this->tipo02('a'), 1, 1))->assertForbidden();
        $this->getJson('/api/v1/expedientes/'.$persona->id.'/documentos/folio-sugerido?seccion=2')->assertForbidden();
        $this->assertDatabaseCount('documentos_usuario', 0);
    }

    public function test_sensitive_sections_require_datos_sensibles(): void
    {
        config(['permisos.roles.SUBE' => ['legajo.ver', 'documento.subir']]);
        $this->actuarComo(null, 'SUBE');
        $seccion01 = SeccionLegajo::query()->firstOrCreate(['id' => 1], ['nombre' => 'Información personal y familiar', 'orden' => 1]);
        $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => $seccion01->id]);

        $this->subir($this->personaDemo(), $this->metadatos($tipo, 1, 1))->assertForbidden();
    }
}
