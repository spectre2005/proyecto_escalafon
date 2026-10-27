<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\ConfiguracionFuncionalidad;
use App\Models\DeclaracionVerificacion;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\RequerimientoDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

/** ZIP del expediente imprimible (fase 2, secciones 01 a 04). Datos ficticios y discos simulados. */
class ZipExpedienteTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $persona;

    private Legajo $legajo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('legajo.disco_documentos'));
        Storage::fake('local');
        $this->actuarComo();
        $this->persona = Usuario::factory()->create(['nombres' => 'ANA', 'apellido_paterno' => 'FICTICIA', 'apellido_materno' => 'PRUEBA', 'numero_doc_identidad' => '00000001']);
        $this->legajo = Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'activo', 'numero_legajo' => 'LEG-PRUEBA-1']);
    }

    private function url(): string
    {
        return '/api/v1/expedientes/'.$this->persona->id.'/expediente-imprimible/zip';
    }

    private function documento(string $codigo, int $inicio, int $fin, array $datos = [], array $archivo = [], bool $guardar = true): DocumentoUsuario
    {
        $tipo = TipoDocumento::query()->where('codigo', $codigo)->sole();
        $ruta = 'legajos/prueba/'.Str::uuid().'.pdf';
        if ($guardar) {
            Storage::disk(config('legajo.disco_documentos'))->put($ruta, "%PDF-1.4 {$codigo} {$inicio}\n%%EOF");
        }
        $registro = ArchivoDocumento::factory()->create(['almacenamiento' => config('legajo.disco_documentos'), 'storage_path' => $ruta, 'num_paginas' => $fin - $inicio + 1, ...$archivo]);

        return DocumentoUsuario::factory()->create(['usuario_id' => $this->persona->id, 'legajo_id' => $this->legajo->id, 'tipo_documento_id' => $tipo->id,
            'seccion_legajo_id' => $tipo->seccion_legajo_id, 'archivo_id' => $registro->id, 'folio_inicio' => $inicio, 'folio_fin' => $fin, 'estado' => 'aceptado', ...$datos]);
    }

    /** @return array<string, string> nombre => contenido */
    private function contenido(TestResponse $respuesta): array
    {
        $ruta = $respuesta->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $archivos = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $archivos[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($ruta);

        return $archivos;
    }

    public function test_zip_has_the_printable_originals_by_section_and_folio_and_an_html_index(): void
    {
        $this->documento('S01_B_DOCUMENTO_IDENTIDAD', 1, 2, ['nombre' => 'DNI vigente']);
        $this->documento('S02_F_CONTRATO', 1, 3, ['num_resolucion' => 'CONTRATO-001']);
        $this->documento('FORM_TITULO_GRADO_SUPERIOR', 1, 1);
        $this->documento('EXP_CERTIFICADO_TRABAJO', 1, 2);
        $respuesta = $this->get($this->url())->assertOk()->assertHeader('Content-Type', 'application/zip')
            ->assertDownload('leg_prueba_1_ana_ficticia_prueba_secciones_01-04.zip');
        $archivos = $this->contenido($respuesta);
        $this->assertSame(['00_INDICE.html', '01_informacion_personal_y_familiar/F0001-0002_S01_B_DOCUMENTO_IDENTIDAD.pdf',
            '02_incorporacion/F0001-0003_S02_F_CONTRATO.pdf', '03_formacion_academica_y_capacitacion/F0001-0001_FORM_TITULO_GRADO_SUPERIOR.pdf',
            '04_experiencia_laboral/F0001-0002_EXP_CERTIFICADO_TRABAJO.pdf'], collect(array_keys($archivos))->sort()->values()->all());
        // Copia exacta del original.
        $this->assertSame("%PDF-1.4 S02_F_CONTRATO 1\n%%EOF", $archivos['02_incorporacion/F0001-0003_S02_F_CONTRATO.pdf']);
        $indice = $archivos['00_INDICE.html'];
        foreach (['LEGAJO DE PERSONAL (CONFIDENCIAL)', 'ANA FICTICIA PRUEBA', 'DNI 00000001', 'LEG-PRUEBA-1', 'Siempre incluye las secciones 01 a 04', 'no representa necesariamente el legajo físico completo',
            'Acceso completo', 'Sin incidencias', 'num. 9.3', 'DNI vigente', 'CONTRATO-001', '02_incorporacion/F0001-0003_S02_F_CONTRATO.pdf'] as $texto) {
            $this->assertStringContainsString($texto, $indice);
        }
    }

    public function test_annulled_missing_and_infected_documents_stay_out_but_are_listed_in_the_index(): void
    {
        $this->documento('FORM_TITULO_GRADO_SUPERIOR', 1, 1);
        $this->documento('FORM_CAPACITACION', 2, 2, ['estado' => 'anulado']);
        $this->documento('FORM_POSTGRADO', 3, 3, guardar: false);
        $this->documento('FORM_CONGRESOS', 4, 4, archivo: ['estado_antivirus' => 'infectado']);
        $archivos = $this->contenido($this->get($this->url())->assertOk());
        $this->assertSame(['00_INDICE.html', '03_formacion_academica_y_capacitacion/F0001-0001_FORM_TITULO_GRADO_SUPERIOR.pdf'], collect(array_keys($archivos))->sort()->values()->all());
        $indice = $archivos['00_INDICE.html'];
        $this->assertStringContainsString('No incluido: anulado (pendiente de confirmar con la ORH).', $indice);
        $this->assertSame(2, substr_count($indice, 'No incluido: archivo no disponible.'));
        $this->assertStringContainsString('2 incidencia(s)', $indice);
        $this->assertStringContainsString('No es una copia íntegra', $indice);
        // Los folios se conservan en el índice: F2 (anulado), F3 y F4 (no disponibles).
        foreach (['<td>F2</td>', '<td>F3</td>', '<td>F4</td>'] as $folio) {
            $this->assertStringContainsString($folio, $indice);
        }
    }

    public function test_restricted_disabled_and_unincorporated_documents_are_never_copied_or_described(): void
    {
        $dni = $this->documento('S01_B_DOCUMENTO_IDENTIDAD', 1, 1, ['nombre' => 'DNI reservado']);
        $this->documento('S01_I_DNI_CONYUGE_CONCUBINO', 2, 2);
        $this->documento('EXP_CERTIFICADO_TRABAJO', 1, 1);
        $this->documento('FORM_TITULO_GRADO_SUPERIOR', 1, 1, ['nombre' => '<script>alert(1)</script>']);
        ConfiguracionFuncionalidad::factory()->create(['codigo' => 's04.experiencia', 'activa' => false]);
        RequerimientoDocumento::factory()->create(['usuario_id' => $this->persona->id, 'estado' => 'en_revision',
            'seguimiento' => [['accion' => 'respuesta', 'canal' => 'gmail', 'archivos' => [['archivo' => 'correo.pdf', 'ruta' => 'solicitudes/x/correo.pdf', 'disco' => 'documentos', 'hash' => 'h']]]]]);
        $this->actuarComo(codigo: 'asistente_legajo');
        $archivos = $this->contenido($this->get($this->url())->assertOk());
        $this->assertSame(['00_INDICE.html', '03_formacion_academica_y_capacitacion/F0001-0001_FORM_TITULO_GRADO_SUPERIOR.pdf'], collect(array_keys($archivos))->sort()->values()->all());
        $indice = $archivos['00_INDICE.html'];
        foreach ([$dni->id, 'DNI reservado', 'S01_B_DOCUMENTO_IDENTIDAD', 'S01_I_DNI_CONYUGE_CONCUBINO', 'correo.pdf', 'solicitudes/', '<script>'] as $ausente) {
            $this->assertStringNotContainsString($ausente, $indice);
        }
        $this->assertStringContainsString('&lt;script&gt;', $indice);
        $this->assertStringContainsString('Omitida por permisos', $indice);
        $this->assertStringContainsString('Acceso parcial', $indice);
        $this->assertStringContainsString('Funcionalidad desactivada en la institución', $indice);
    }

    /** Todo el ZIP como texto: nombres de archivo y contenido (el índice incluido), para buscar datos restringidos. */
    private function todoElZip(array $archivos): string
    {
        return implode("\n", array_keys($archivos))."\n".implode("\n", $archivos);
    }

    public function test_index_never_shows_the_dni_or_restricted_metadata_to_a_profile_without_sensitive_data(): void
    {
        $dni = $this->documento('S01_B_DOCUMENTO_IDENTIDAD', 1, 2, ['nombre' => 'Copia DNI reservada', 'emisor' => 'RENIEC-RESERVADO', 'num_resolucion' => 'RES-SENSIBLE-9']);
        $this->documento('FORM_TITULO_GRADO_SUPERIOR', 1, 1, ['nombre' => 'Título permitido', 'emisor' => 'Universidad Ficticia']);
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->assertFalse(auth()->user()->veDatosSensibles());
        $archivos = $this->contenido($this->get($this->url())->assertOk());
        $todo = $this->todoElZip($archivos);
        // Ni el número de DNI (en claro, escapado o en atributos) ni los datos del documento de la sección 01.
        foreach (['00000001', e('00000001'), 'DNI 00000001', 'DNI', $dni->id, 'Copia DNI reservada', 'RENIEC-RESERVADO', 'RES-SENSIBLE-9',
            'S01_B_DOCUMENTO_IDENTIDAD', 'F0001-0002_S01'] as $restringido) {
            $this->assertStringNotContainsString($restringido, $todo, "Aparece «{$restringido}» en el ZIP");
        }
        $this->assertSame(['00_INDICE.html', '03_formacion_academica_y_capacitacion/F0001-0001_FORM_TITULO_GRADO_SUPERIOR.pdf'], collect(array_keys($archivos))->sort()->values()->all());
        $indice = $archivos['00_INDICE.html'];
        $this->assertStringContainsString('<h2>01. Información personal y familiar</h2><p class="nota">Omitida por permisos: no se incluye en esta copia.</p>', $indice);
        $this->assertStringContainsString('Acceso parcial', $indice);
        // Lo permitido sigue apareciendo.
        foreach (['ANA FICTICIA PRUEBA', 'LEG-PRUEBA-1', 'Título permitido', 'Universidad Ficticia', 'LEGAJO DE PERSONAL (CONFIDENCIAL)'] as $permitido) {
            $this->assertStringContainsString($permitido, $indice);
        }
    }

    public function test_an_authorized_profile_keeps_the_dni_in_the_cover_and_the_section_01_documents(): void
    {
        $this->documento('S01_B_DOCUMENTO_IDENTIDAD', 1, 2, ['nombre' => 'Copia DNI reservada', 'emisor' => 'RENIEC-RESERVADO']);
        $this->assertTrue(auth()->user()->veDatosSensibles());
        $archivos = $this->contenido($this->get($this->url())->assertOk());
        $this->assertArrayHasKey('01_informacion_personal_y_familiar/F0001-0002_S01_B_DOCUMENTO_IDENTIDAD.pdf', $archivos);
        foreach (['DNI 00000001', 'Copia DNI reservada', 'RENIEC-RESERVADO', 'Acceso completo'] as $texto) {
            $this->assertStringContainsString($texto, $archivos['00_INDICE.html']);
        }
    }

    public function test_export_is_audited_and_each_copied_document_is_logged(): void
    {
        $dni = $this->documento('S01_B_DOCUMENTO_IDENTIDAD', 1, 1);
        $titulo = $this->documento('FORM_TITULO_GRADO_SUPERIOR', 1, 1);
        $this->documento('FORM_CAPACITACION', 2, 2, ['estado' => 'anulado']);
        Storage::disk('local')->put('declaraciones/penal.pdf', "%PDF-1.4\n%%EOF");
        DeclaracionVerificacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'antecedente_penal', 'anulado' => false, 'pdf_path' => 'declaraciones/penal.pdf']);
        $archivos = $this->contenido($this->get($this->url())->assertOk());
        $this->assertArrayHasKey('01_informacion_personal_y_familiar/SF01_S01_C_ANTECEDENTES_PENALES.pdf', $archivos);
        $this->assertSame(3, DB::table('bitacora_accesos_legajo')->where('legajo_id', $this->legajo->id)->where('accion', 'descargar')->count());
        $this->assertEqualsCanonicalizing([$dni->id, $titulo->id, null], DB::table('bitacora_accesos_legajo')->pluck('documento_id')->all());
        $evento = DB::table('auditoria_eventos')->where('accion', 'expediente.exportado')->sole();
        $this->assertSame($this->persona->id, $evento->usuario_titular_id);
        $this->assertSame(['formato' => 'zip', 'secciones' => [1, 2, 3, 4], 'incluidos' => [], 'orden' => 'asc', 'documentos' => 3, 'acceso_completo' => true, 'integro' => true], json_decode($evento->datos_despues, true));
    }

    public function test_optional_groups_are_only_included_when_chosen(): void
    {
        $this->documento('S01_B_DOCUMENTO_IDENTIDAD', 1, 1);
        $this->documento('S01_I_DNI_CONYUGE_CONCUBINO', 2, 2);
        $this->documento('S05_B_GOCE_VACACIONAL', 1, 1);
        // Por defecto: solo 01 a 04.
        $base = $this->contenido($this->get($this->url())->assertOk()->assertDownload('leg_prueba_1_ana_ficticia_prueba_secciones_01-04.zip'));
        $this->assertSame(['00_INDICE.html', '01_informacion_personal_y_familiar/F0001-0001_S01_B_DOCUMENTO_IDENTIDAD.pdf'], collect(array_keys($base))->sort()->values()->all());
        $this->assertStringContainsString('No se incluyó: familiares', $base['00_INDICE.html']);
        // Elegidos: familiares y sección 05, con sus folios y sección propia.
        $respuesta = $this->get($this->url().'?incluir[]=familiares&incluir[]=seccion_05')->assertOk()->assertDownload('leg_prueba_1_ana_ficticia_prueba_expediente_ampliado.zip');
        $ampliado = $this->contenido($respuesta);
        $this->assertArrayHasKey('01_informacion_personal_y_familiar/F0002-0002_S01_I_DNI_CONYUGE_CONCUBINO.pdf', $ampliado);
        $this->assertArrayHasKey('05_movimientos_del_personal/F0001-0001_S05_B_GOCE_VACACIONAL.pdf', $ampliado);
        $this->assertStringContainsString('Añadido al descargar: Familiares (DNI de cónyuge e hijos, partida de matrimonio); 05. Movimientos del personal.', $ampliado['00_INDICE.html']);
        $this->assertStringNotContainsString('folios_no_incluidos', $ampliado['00_INDICE.html']);
        $evento = DB::table('auditoria_eventos')->where('accion', 'expediente.exportado')->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn ($e) => json_decode($e->datos_despues, true))->firstWhere('incluidos', ['familiares', 'seccion_05']);
        $this->assertSame([1, 2, 3, 4, 5], $evento['secciones']);
        $this->getJson($this->url().'?incluir[]=otra_cosa')->assertUnprocessable()->assertJsonValidationErrors('incluir.0');
    }

    public function test_a_chosen_sensitive_group_is_still_omitted_for_a_profile_without_permission(): void
    {
        $this->documento('S10_EXAMEN_OCUPACIONAL', 1, 1, ['nombre' => 'Examen reservado']);
        $this->documento('FORM_TITULO_GRADO_SUPERIOR', 1, 1);
        $this->actuarComo(codigo: 'asistente_legajo');
        $archivos = $this->contenido($this->get($this->url().'?incluir[]=seccion_10')->assertOk());
        $this->assertSame(['00_INDICE.html', '03_formacion_academica_y_capacitacion/F0001-0001_FORM_TITULO_GRADO_SUPERIOR.pdf'], collect(array_keys($archivos))->sort()->values()->all());
        $this->assertStringNotContainsString('Examen reservado', $archivos['00_INDICE.html']);
        $this->assertStringNotContainsString('S10_EXAMEN_OCUPACIONAL', $archivos['00_INDICE.html']);
        $this->assertStringContainsString('<h2>10. SST y bienestar</h2><p class="nota">Omitida por permisos', $archivos['00_INDICE.html']);
    }

    public function test_permissions_and_missing_legajo(): void
    {
        config(['permisos.roles.prueba_sin_legajo' => ['requerimientos.gestionar']]);
        $this->actuarComo(codigo: 'prueba_sin_legajo');
        $this->get($this->url())->assertForbidden();
        $this->actuarComo();
        $this->get('/api/v1/expedientes/'.Usuario::factory()->create()->id.'/expediente-imprimible/zip')->assertStatus(409);
        $this->assertSame(0, DB::table('auditoria_eventos')->where('accion', 'expediente.exportado')->count());
    }
}
