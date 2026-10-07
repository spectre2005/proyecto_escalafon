<?php

namespace Tests\Feature;

use App\Models\BitacoraAccesoLegajo;
use App\Models\CertificadoDiscapacidad;
use App\Models\DeclaracionVerificacion;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Database\Seeders\Seccion01CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

/** Sección 01 con el servicio genérico de documentos: enlace a sus datos, exigencias por familiar y resumen. */
class Seccion01DocumentosTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    private int $folio = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('legajo.disco_documentos'));
        Storage::fake('local');
        $this->seed(Seccion01CatalogoSeeder::class);
        $this->persona = Usuario::factory()->create(['numero_doc_identidad' => '40000001', 'obligado_djbr' => false, 'con_discapacidad' => false, 'tiene_derechohabientes' => false]);
        Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'activo']);
    }

    private function tipo01(string $codigo): TipoDocumento
    {
        return TipoDocumento::query()->where('seccion_legajo_id', 1)->where('codigo', $codigo)->where('activo', true)->firstOrFail();
    }

    /** Sube un documento de la sección 01 en los folios siguientes. */
    private function subir01(string $codigo, array $cambios = []): TestResponse
    {
        $this->folio++;

        if ($codigo === 'S01_B_DOCUMENTO_IDENTIDAD') {
            $cambios = ['fecha_caducidad' => today()->addYears(8)->toDateString(), ...$cambios];
        }
        $respuesta = $this->subir($this->persona, $this->metadatos($this->tipo01($codigo), $this->folio, $this->folio, $cambios));
        if ($respuesta->status() === 201) {
            $this->folio = $respuesta->json('data.folio_fin');
        }

        return $respuesta;
    }

    public function test_dni_upload_uses_person_identity_and_automatic_folios_and_keeps_previous_pdf(): void
    {
        $this->actuarComo();
        $this->subir01('S01_A_FICHA_DATOS')->assertCreated();
        $datos = [
            'seccion_legajo_id' => 1, 'tipo_documento_id' => $this->tipo01('S01_B_DOCUMENTO_IDENTIDAD')->id,
            'pdf' => $this->pdf(), 'fecha_incorporacion' => today()->toDateString(),
            'fecha_emision' => today()->subYear()->toDateString(), 'fecha_caducidad' => today()->addYears(7)->toDateString(),
            'titular_nombre' => 'Otro nombre', 'titular_documento' => '99999999', 'folio_inicio' => 500, 'folio_fin' => 999,
        ];
        $id = $this->subir($this->persona, $datos)->assertCreated()
            ->assertJsonPath('data.folio_inicio', 2)->assertJsonPath('data.folio_fin', 3)->json('data.id');
        $documento = DocumentoUsuario::query()->findOrFail($id);
        $this->assertSame($this->persona->numero_doc_identidad, $documento->titular_documento);
        $this->assertSame(trim($this->persona->nombres.' '.$this->persona->apellido_paterno.' '.$this->persona->apellido_materno), $documento->titular_nombre);
        $this->assertSame($datos['fecha_caducidad'], $documento->fecha_caducidad->toDateString());
        $this->assertNull($documento->emisor);
        $this->assertNull($documento->modalidad_presentacion);
        $this->assertSame('servidor', $documento->relacion_titular);

        $nuevo = $this->subir($this->persona, [...$datos, 'pdf' => $this->pdf('dni-renovado.pdf')])->assertCreated()
            ->assertJsonPath('data.folio_inicio', 4)->assertJsonPath('data.folio_fin', 5)
            ->assertJsonPath('data.subsana_a_documento_id', $id)->json('data.id');
        $this->assertNotSame($id, $nuevo);
        Storage::disk(config('legajo.disco_documentos'))->assertExists($documento->archivo->storage_path);
        $lista = collect($this->seccion()['tipos'])->firstWhere('codigo', 'S01_B_DOCUMENTO_IDENTIDAD')['lista'];
        $this->assertCount(2, $lista);
        $this->assertSame($datos['fecha_caducidad'], collect($lista)->firstWhere('id', $nuevo)['values']['fecha_caducidad']);
    }

    public function test_dni_requires_valid_expiry_date_but_accepts_an_expired_document(): void
    {
        $this->actuarComo();
        $datos = [
            'seccion_legajo_id' => 1, 'tipo_documento_id' => $this->tipo01('S01_B_DOCUMENTO_IDENTIDAD')->id,
            'pdf' => $this->pdf(), 'fecha_incorporacion' => today()->toDateString(),
            'fecha_emision' => today()->subYears(9)->toDateString(),
        ];
        $this->subir($this->persona, $datos)->assertUnprocessable()->assertJsonValidationErrors('fecha_caducidad');
        $this->subir($this->persona, [...$datos, 'fecha_caducidad' => today()->subYears(10)->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_caducidad');
        $this->subir($this->persona, [...$datos, 'fecha_caducidad' => today()->subYear()->toDateString()])->assertCreated();
    }

    public function test_dni_pdf_without_readable_page_count_does_not_reserve_folios_or_store_a_file(): void
    {
        $this->actuarComo();
        $this->subir01('S01_B_DOCUMENTO_IDENTIDAD', [
            'pdf' => UploadedFile::fake()->createWithContent('sin-paginas.pdf', "%PDF-1.4\n%%EOF\n"),
        ])->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $this->assertDatabaseCount('documentos_usuario', 0);
        $this->assertSame([], Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    private function seccion(): array
    {
        return $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/seccion-01')->assertOk()->json('data');
    }

    private function familiar(array $datos): Familiar
    {
        return Familiar::factory()->create(['usuario_id' => $this->persona->id, 'anulado' => false, ...$datos]);
    }

    public function test_each_type_with_a_data_form_links_to_the_right_record(): void
    {
        $this->actuarComo();
        $declaraciones = [
            'S01_C_ANTECEDENTES_PENALES' => 'antecedente_penal', 'S01_D_ANTECEDENTES_POLICIALES' => 'antecedente_policial',
            'S01_E_DJ_BIENES_RENTAS' => 'djbr', 'S01_F_DJ_NO_IMPEDIMENTOS_31419' => 'dj_no_impedimentos',
            'S01_H_IMPEDIMENTOS_INHABILITACIONES' => 'impedimentos_redjum', 'S01_L_OTRAS_DJ' => 'otra_dj',
        ];
        foreach ($declaraciones as $codigo => $tipo) {
            $registro = DeclaracionVerificacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => $tipo]);
            $documento = $this->subir01($codigo, ['enlace_id' => $registro->id])->assertCreated()
                ->assertJsonPath('data.formulario_datos', 'declaraciones_verificaciones')->assertJsonPath('data.enlazado', true)->json('data.id');
            $this->assertSame($documento, $registro->refresh()->documento_id, $codigo);
        }
        $certificado = CertificadoDiscapacidad::factory()->create(['usuario_id' => $this->persona->id]);
        $k = $this->subir01('S01_K_CERTIFICADO_DISCAPACIDAD', ['enlace_id' => $certificado->id])->assertCreated()->json('data.id');
        $this->assertSame($k, $certificado->refresh()->documento_id);
        $conyuge = $this->familiar(['parentesco' => 'conyuge']);
        $hijo = $this->familiar(['parentesco' => 'hijo', 'fecha_nacimiento' => today()->subYears(8)]);
        $i = $this->subir01('S01_I_DNI_CONYUGE_CONCUBINO', ['enlace_id' => $conyuge->id, 'relacion_titular' => 'conyuge_concubino'])->assertCreated()->json('data.id');
        $j = $this->subir01('S01_J_DNI_HIJOS_MENORES', ['enlace_id' => $hijo->id, 'relacion_titular' => 'hijo'])->assertCreated()->json('data.id');
        $this->assertSame([$i, $j], [$conyuge->refresh()->documento_id, $hijo->refresh()->documento_id]);

        // Registro de datos creado después de subir el documento (el formulario se abre al subirlo).
        $penal = $this->subir01('S01_C_ANTECEDENTES_PENALES', ['subsana_a_documento_id' => DocumentoUsuario::query()->where('tipo_documento_id', $this->tipo01('S01_C_ANTECEDENTES_PENALES')->id)->value('id')])
            ->assertCreated()->assertJsonPath('data.enlazado', false)->json('data.id');
        $url = '/api/v1/expedientes/'.$this->persona->id;
        $datos = ['tipo' => 'antecedente_penal', 'fecha' => '2026-01-10', 'resultado' => 'sin_registros'];
        $this->postJson($url.'/declaraciones-verificaciones', ['tipo' => 'djbr'] + $datos + ['documento_id' => $penal])->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $this->postJson($url.'/declaraciones-verificaciones', $datos + ['documento_id' => $penal])->assertCreated()->assertJsonPath('data.documento_id', $penal);
        // Un documento acredita un solo registro.
        $this->postJson($url.'/declaraciones-verificaciones', $datos + ['documento_id' => $penal])->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $hija = $this->subir01('S01_J_DNI_HIJOS_MENORES', ['relacion_titular' => 'hijo'])->assertCreated()->assertJsonPath('data.formulario_datos', 'familiares')->json('data.id');
        $familiar = ['apellidos_nombres' => 'Hija Demo', 'tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => '70000001', 'fecha_nacimiento' => today()->subYears(4)->toDateString(), 'es_derechohabiente' => true];
        $this->postJson($url.'/familiares', $familiar + ['parentesco' => 'conyuge', 'documento_id' => $hija])->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $id = $this->postJson($url.'/familiares', $familiar + ['parentesco' => 'hijo', 'documento_id' => $hija])->assertCreated()->json('data.id');
        $this->assertSame($hija, Familiar::query()->findOrFail($id)->documento_id);
        // El enlace no cambia después, ni el parentesco a uno que no corresponde al documento.
        $this->patchJson($url.'/familiares/'.$id, ['documento_id' => $i])->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $this->patchJson($url.'/familiares/'.$id, ['parentesco' => 'conyuge'])->assertUnprocessable()->assertJsonValidationErrors('parentesco');
        $declaracion = DeclaracionVerificacion::query()->where('documento_id', $penal)->firstOrFail();
        $this->patchJson($url.'/declaraciones-verificaciones/'.$declaracion->id, ['tipo' => 'djbr'] + $datos)->assertUnprocessable()->assertJsonValidationErrors('tipo');

        // Enlace incompatible al subir: c) a una declaración de bienes y rentas; i) a un hijo. No queda nada cargado.
        $djbr = DeclaracionVerificacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'djbr']);
        $antes = DocumentoUsuario::query()->count();
        $this->subir($this->persona, $this->metadatos($this->tipo01('S01_C_ANTECEDENTES_PENALES'), $this->folio + 1, $this->folio + 1, ['enlace_id' => $djbr->id, 'subsana_a_documento_id' => $penal]))
            ->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $otroHijo = $this->familiar(['parentesco' => 'hijo']);
        $this->subir($this->persona, $this->metadatos($this->tipo01('S01_I_DNI_CONYUGE_CONCUBINO'), $this->folio + 1, $this->folio + 1, ['enlace_id' => $otroHijo->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->assertSame($antes, DocumentoUsuario::query()->count());
        $this->assertNull($djbr->refresh()->documento_id);
    }

    public function test_g_has_no_data_link_and_types_without_form_keep_only_metadata(): void
    {
        $this->actuarComo();
        $conyuge = $this->familiar(['parentesco' => 'conyuge']);
        $this->subir01('S01_G_PARTIDA_MATRIMONIO_CONCUBINATO', ['enlace_id' => $conyuge->id])->assertUnprocessable()->assertJsonValidationErrors('enlace_id');
        $this->folio--;
        $g = $this->subir01('S01_G_PARTIDA_MATRIMONIO_CONCUBINATO', ['titular_nombre' => 'Servidor y cónyuge', 'relacion_titular' => 'conyuge_concubino'])
            ->assertCreated()->assertJsonPath('data.formulario_datos', null)->assertJsonPath('data.enlazado', false)->json('data.id');
        $this->assertNull($conyuge->refresh()->documento_id);
        $documento = DocumentoUsuario::query()->findOrFail($g);
        $this->assertSame(['Servidor y cónyuge', 'conyuge_concubino', 1, 1], [$documento->titular_nombre, $documento->relacion_titular, $documento->folio_inicio, $documento->folio_fin]);

        foreach (['S01_A_FICHA_DATOS', 'S01_B_DOCUMENTO_IDENTIDAD', 'S01_OTROS_UNIVERSIDAD'] as $codigo) {
            $id = $this->subir01($codigo)->assertCreated()->assertJsonPath('data.formulario_datos', null)->json('data.id');
            $this->assertSame($codigo === 'S01_B_DOCUMENTO_IDENTIDAD' ? null : 'Oficina de Recursos Humanos', DocumentoUsuario::query()->findOrFail($id)->emisor);
        }
        $this->assertSame(0, Familiar::query()->whereNotNull('documento_id')->count() + DeclaracionVerificacion::query()->whereNotNull('documento_id')->count());
    }

    public function test_writing_and_reading_require_sensitive_permission_and_upload_permission(): void
    {
        $this->actuarComo();
        $id = $this->subir01('S01_A_FICHA_DATOS')->assertCreated()->json('data.id');
        $base = '/api/v1/expedientes/'.$this->persona->id;

        config(['permisos.roles.cargador_sin_sensibles' => ['legajo.ver', 'documento.subir', 'documento.recepcionar']]);
        Sanctum::actingAs($this->asignarRol(Usuario::factory()->create(), 'cargador_sin_sensibles', [], ['ve_datos_sensibles' => false])->refresh());
        $this->subir01('S01_B_DOCUMENTO_IDENTIDAD')->assertForbidden();
        $this->getJson($base.'/documentos/folio-sugerido?seccion=1')->assertForbidden();
        $this->getJson($base.'/seccion-01')->assertForbidden();
        $this->get($base.'/documentos/'.$id.'/archivo')->assertForbidden();
        $this->postJson($base.'/documentos/'.$id.'/recepcionar')->assertForbidden();

        config(['permisos.roles.lector_sensibles' => ['legajo.ver']]);
        Sanctum::actingAs($this->asignarRol(Usuario::factory()->create(), 'lector_sensibles', [], ['ve_datos_sensibles' => true])->refresh());
        $this->subir01('S01_B_DOCUMENTO_IDENTIDAD')->assertForbidden();
        $this->getJson($base.'/seccion-01')->assertOk();
        $this->get($base.'/documentos/'.$id.'/archivo')->assertOk();
        $this->assertSame(1, DocumentoUsuario::query()->count());
    }

    public function test_viewer_and_download_write_the_access_log(): void
    {
        $admin = $this->actuarComo();
        $id = $this->subir01('S01_C_ANTECEDENTES_PENALES')->assertCreated()->json('data.id');
        $url = '/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$id.'/archivo';
        $this->get($url.'?accion=ver')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($url.'?accion=descargar')->assertOk()->assertDownload();
        $this->assertSame(['descargar', 'ver'], BitacoraAccesoLegajo::query()->where('documento_id', $id)->where('usuario_id', $admin->id)->orderBy('accion')->pluck('accion')->all());
    }

    public function test_requirements_are_computed_per_relative(): void
    {
        $this->actuarComo();
        $conyuge = $this->familiar(['parentesco' => 'concubino', 'apellidos_nombres' => 'Concubino Demo']);
        $menorA = $this->familiar(['parentesco' => 'hijo', 'apellidos_nombres' => 'Hijo A', 'fecha_nacimiento' => today()->subYears(10)]);
        $menorB = $this->familiar(['parentesco' => 'hijo', 'apellidos_nombres' => 'Hijo B', 'fecha_nacimiento' => today()->subYears(17)]);
        $sinFecha = $this->familiar(['parentesco' => 'hijo', 'apellidos_nombres' => 'Hijo C', 'fecha_nacimiento' => null]);
        $mayor = $this->familiar(['parentesco' => 'hijo', 'apellidos_nombres' => 'Hijo D', 'fecha_nacimiento' => today()->subYears(18)]);
        $this->familiar(['parentesco' => 'otro', 'apellidos_nombres' => 'Otro familiar']);
        $this->familiar(['parentesco' => 'hijo', 'apellidos_nombres' => 'Hijo anulado', 'fecha_nacimiento' => today()->subYears(3), 'anulado' => true, 'motivo_anulacion' => 'Duplicado']);

        $data = $this->seccion();
        $familiares = collect($data['familiares'])->keyBy('id');
        $exigencia = fn (Familiar $familiar): ?bool => $familiares[$familiar->id]['exigencias'][0]['exigido'];
        $this->assertTrue($exigencia($conyuge));
        $this->assertSame('i', $familiares[$conyuge->id]['exigencias'][0]['literal']);
        $this->assertTrue($exigencia($menorA));
        $this->assertTrue($exigencia($menorB));
        $this->assertNull($exigencia($sinFecha));
        $this->assertFalse($exigencia($mayor));
        $this->assertSame([10, true], [$familiares[$menorA->id]['edad'], $familiares[$menorA->id]['menor_de_edad']]);
        $this->assertSame([18, false], [$familiares[$mayor->id]['edad'], $familiares[$mayor->id]['menor_de_edad']]);
        $this->assertCount(6, $familiares);
        $this->assertSame([], $familiares->firstWhere('values.parentesco', 'otro')['exigencias']);
        $tipos = collect($data['tipos'])->keyBy('codigo');
        $this->assertTrue($tipos['S01_G_PARTIDA_MATRIMONIO_CONCUBINATO']['exigido']);
        $this->assertTrue($tipos['S01_J_DNI_HIJOS_MENORES']['exigido']);
        // Exigidos: uno por tipo (a, b, c, d, h, g) y uno por familiar (concubino y dos hijos menores); el hijo sin fecha queda por determinar.
        $this->assertSame([9, 9, 0, 0, 1], [$data['resumen']['exigidos'], $data['resumen']['pendientes'], $data['resumen']['cargados'], $data['resumen']['recepcionados'], $data['resumen']['por_determinar']]);

        // Al cumplir 18 años deja de exigirse; el documento se conserva y sigue visible.
        $doc = $this->subir01('S01_J_DNI_HIJOS_MENORES', ['enlace_id' => $menorB->id, 'relacion_titular' => 'hijo'])->assertCreated()->json('data.id');
        $this->assertSame(['exigido' => true, 'cargado' => true], array_intersect_key(collect($this->seccion()['familiares'])->firstWhere('id', $menorB->id)['exigencias'][0], ['exigido' => 0, 'cargado' => 0]));
        $this->travel(1)->years();
        $data = $this->seccion();
        $fila = collect($data['familiares'])->firstWhere('id', $menorB->id);
        $this->assertFalse($fila['exigencias'][0]['exigido']);
        $this->assertSame($doc, $fila['values']['documento_id']);
        $this->assertSame([$doc], array_column(collect($data['tipos'])->firstWhere('codigo', 'S01_J_DNI_HIJOS_MENORES')['lista'], 'id'));
        $this->assertDatabaseHas('documentos_usuario', ['id' => $doc]);

        // Sin cónyuge ni hijos menores: g, i y j no se exigen.
        Familiar::query()->whereKey([$conyuge->id, $menorA->id])->update(['anulado' => true, 'motivo_anulacion' => 'Prueba']);
        $tipos = collect($this->seccion()['tipos'])->keyBy('codigo');
        $this->assertFalse($tipos['S01_G_PARTIDA_MATRIMONIO_CONCUBINATO']['exigido']);
        $this->assertFalse($tipos['S01_I_DNI_CONYUGE_CONCUBINO']['exigido']);
        // Queda el hijo sin fecha de nacimiento: j no se puede determinar.
        $this->assertNull($tipos['S01_J_DNI_HIJOS_MENORES']['exigido']);
    }

    public function test_e_and_k_do_not_apply_without_their_flags(): void
    {
        $this->actuarComo();
        $tipos = collect($this->seccion()['tipos'])->keyBy('codigo');
        foreach (['S01_E_DJ_BIENES_RENTAS', 'S01_K_CERTIFICADO_DISCAPACIDAD'] as $codigo) {
            $this->assertSame([false, false], [$tipos[$codigo]['aplica'], $tipos[$codigo]['exigido']], $codigo);
        }
        $this->assertTrue($tipos['S01_A_FICHA_DATOS']['aplica']);
        // f no se ata a ninguna bandera: opcional, sin atenuar.
        $this->assertSame([true, false], [$tipos['S01_F_DJ_NO_IMPEDIMENTOS_31419']['aplica'], $tipos['S01_F_DJ_NO_IMPEDIMENTOS_31419']['exigido']]);
        $this->assertTrue($tipos['S01_X_DJ_INTERESES']['solo_anexo_05']);

        $this->persona->update(['obligado_djbr' => true, 'con_discapacidad' => true]);
        $tipos = collect($this->seccion()['tipos'])->keyBy('codigo');
        foreach (['S01_E_DJ_BIENES_RENTAS', 'S01_K_CERTIFICADO_DISCAPACIDAD'] as $codigo) {
            $this->assertSame([true, true], [$tipos[$codigo]['aplica'], $tipos[$codigo]['exigido']], $codigo);
        }
    }

    public function test_previous_pdfs_are_shown_without_invented_folios(): void
    {
        $this->actuarComo();
        $declaracion = DeclaracionVerificacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'antecedente_penal']);
        $certificado = CertificadoDiscapacidad::factory()->create(['usuario_id' => $this->persona->id]);
        foreach ([$declaracion, $certificado] as $registro) {
            Storage::disk('local')->put('anterior/'.$registro->id.'.pdf', '%PDF-1.4');
            DB::table($registro->getTable())->where('id', $registro->id)->update(['pdf_path' => 'anterior/'.$registro->id.'.pdf', 'pdf_nombre' => 'anterior.pdf']);
        }
        $data = $this->seccion();
        $this->assertSame('anterior.pdf', collect($data['declaraciones'])->firstWhere('id', $declaracion->id)['pdf_anterior']);
        $this->assertSame('anterior.pdf', collect($data['certificados'])->firstWhere('id', $certificado->id)['pdf_anterior']);
        $this->assertArrayNotHasKey('pdf_path', collect($data['declaraciones'])->firstWhere('id', $declaracion->id)['values']);
        // No se convierten en documentos del legajo: sin folios, sin documento y sin contar como cargados.
        $penales = collect($data['tipos'])->firstWhere('codigo', 'S01_C_ANTECEDENTES_PENALES');
        $this->assertSame([[], false, 'pendiente'], [$penales['lista'], $penales['cargado'], $penales['estado']]);
        $this->assertSame(0, DocumentoUsuario::query()->count());
        $this->assertSame(0, (int) DB::table('legajo_secciones')->max('ultimo_folio'));
    }

    public function test_lists_and_counters_exclude_annulled_records(): void
    {
        $admin = $this->actuarComo();
        $this->familiar(['parentesco' => 'hijo', 'fecha_nacimiento' => today()->subYears(2), 'anulado' => true, 'motivo_anulacion' => 'Duplicado']);
        $anulada = DeclaracionVerificacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'djbr', 'anulado' => true, 'motivo_anulacion' => 'Duplicada']);
        $vigente = DeclaracionVerificacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'otra_dj']);
        CertificadoDiscapacidad::factory()->create(['usuario_id' => $this->persona->id, 'persona' => 'servidor', 'anulado' => true, 'motivo_anulacion' => 'Duplicado']);
        $data = $this->seccion();
        $this->assertSame([], $data['familiares']);
        $this->assertSame([$vigente->id], array_column($data['declaraciones'], 'id'));
        $this->assertSame([], $data['certificados']);
        $this->assertFalse(collect($data['tipos'])->firstWhere('codigo', 'S01_J_DNI_HIJOS_MENORES')['exigido']);
        // Un certificado anulado no cuenta para el aviso de discapacidad.
        $this->assertSame([], $data['avisos']);
        // Administración ve los anulados aparte.
        $this->assertSame([$anulada->id], array_column($data['anulados']['declaraciones'], 'id'));
        $this->assertCount(1, $data['anulados']['familiares']);
        $this->assertNotNull($admin);

        config(['permisos.roles.editor_sensibles' => ['legajo.ver', 'legajo.editar']]);
        Sanctum::actingAs($this->asignarRol(Usuario::factory()->create(), 'editor_sensibles', [], ['ve_datos_sensibles' => true])->refresh());
        $this->assertNull($this->seccion()['anulados']);
    }

    public function test_summary_separates_loaded_and_received(): void
    {
        $this->actuarComo();
        $resumen = $this->seccion()['resumen'];
        // Sin familiares ni banderas: a, b, c, d y h.
        $this->assertSame([5, 5, 0, 0], [$resumen['exigidos'], $resumen['pendientes'], $resumen['cargados'], $resumen['recepcionados']]);
        $a = $this->subir01('S01_A_FICHA_DATOS')->assertCreated()->json('data.id');
        $this->subir01('S01_B_DOCUMENTO_IDENTIDAD')->assertCreated();
        // Un tipo no exigido (opcional) no cambia los conteos.
        $this->subir01('S01_L_OTRAS_DJ')->assertCreated();
        $this->postJson('/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$a.'/recepcionar')->assertOk();
        $resumen = $this->seccion()['resumen'];
        $this->assertSame([5, 3, 2, 1], [$resumen['exigidos'], $resumen['pendientes'], $resumen['cargados'], $resumen['recepcionados']]);
        $this->assertSame(['a', 'b', 'c', 'd', 'h'], array_column($resumen['detalle'], 'literal'));
    }

    public function test_warnings_when_flags_do_not_match_records(): void
    {
        $this->actuarComo();
        $this->persona->update(['tiene_derechohabientes' => true, 'con_discapacidad' => false]);
        CertificadoDiscapacidad::factory()->create(['usuario_id' => $this->persona->id, 'persona' => 'servidor']);
        $avisos = $this->seccion()['avisos'];
        $this->assertCount(2, $avisos);
        $this->assertStringContainsString('con derechohabientes', $avisos[0]);
        $this->assertStringContainsString('figura sin discapacidad', $avisos[1]);

        $this->persona->update(['tiene_derechohabientes' => false, 'con_discapacidad' => true]);
        $this->familiar(['parentesco' => 'hijo', 'es_derechohabiente' => true]);
        $this->assertStringContainsString('sin derechohabientes', $this->seccion()['avisos'][0]);
        $this->assertCount(1, $this->seccion()['avisos']);
    }
}
