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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Vista previa del expediente imprimible (fase 1, secciones 01 a 04). Datos ficticios y discos simulados. */
class ExpedienteImprimibleTest extends TestCase
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
        $this->persona = Usuario::factory()->create(['nombres' => 'ANA', 'apellido_paterno' => 'FICTICIA', 'apellido_materno' => 'PRUEBA']);
        $this->legajo = Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'activo', 'numero_legajo' => 'LEG-PRUEBA-1']);
    }

    private function url(string $orden = ''): string
    {
        return '/api/v1/expedientes/'.$this->persona->id.'/expediente-imprimible'.($orden ? '?orden='.$orden : '');
    }

    /** Documento incorporado con su PDF (o sin él, si $guardar es false). */
    private function documento(string $codigo, ?int $inicio, ?int $fin, array $datos = [], array $archivo = [], bool $guardar = true, ?Legajo $legajo = null): DocumentoUsuario
    {
        $tipo = TipoDocumento::query()->where('codigo', $codigo)->sole();
        $ruta = 'legajos/prueba/'.Str::uuid().'.pdf';
        if ($guardar) {
            Storage::disk(config('legajo.disco_documentos'))->put($ruta, "%PDF-1.4\n%%EOF");
        }
        $registro = ArchivoDocumento::factory()->create(['almacenamiento' => config('legajo.disco_documentos'), 'storage_path' => $ruta, 'num_paginas' => $fin && $inicio ? $fin - $inicio + 1 : null, ...$archivo]);
        $legajo ??= $this->legajo;

        return DocumentoUsuario::factory()->create(['usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id, 'tipo_documento_id' => $tipo->id,
            'seccion_legajo_id' => $tipo->seccion_legajo_id, 'archivo_id' => $registro->id, 'folio_inicio' => $inicio, 'folio_fin' => $fin, 'estado' => 'aceptado', ...$datos]);
    }

    private function seccion(array $data, int $id): array
    {
        return collect($data['secciones'])->firstWhere('id', $id);
    }

    public function test_lists_documents_by_section_and_folio_in_both_orders_with_their_metadata_and_zip_names(): void
    {
        $dni = $this->documento('S01_B_DOCUMENTO_IDENTIDAD', 1, 2, ['nombre' => 'DNI vigente', 'emisor' => 'RENIEC', 'fecha_emision' => '2024-03-01', 'fecha_incorporacion' => '2026-10-01']);
        $ficha = $this->documento('S01_A_FICHA_DATOS', 3, 3);
        $this->documento('S02_F_CONTRATO', 1, 4, ['num_resolucion' => 'CONTRATO-001']);
        $this->documento('FORM_TITULO_GRADO_SUPERIOR', 1, 1);
        $this->documento('EXP_CERTIFICADO_TRABAJO', 1, 2);
        $data = $this->getJson($this->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $s01 = $this->seccion($data, 1);
        $this->assertSame([$dni->id, $ficha->id], array_column($s01['documentos'], 'id'));
        $this->assertSame('01_informacion_personal_y_familiar/F0001-0002_S01_B_DOCUMENTO_IDENTIDAD.pdf', $s01['documentos'][0]['nombre_zip']);
        $this->assertSame(['titulo' => 'DNI vigente', 'emisor' => 'RENIEC', 'fecha_emision' => '2024-03-01', 'fecha_incorporacion' => '2026-10-01',
            'num_resolucion' => null, 'estado' => 'aceptado', 'subsanado' => false, 'subsana_a_otro' => false], $s01['documentos'][0]['registro']);
        $this->assertSame(['estado' => 'disponible', 'paginas' => 2], $s01['documentos'][0]['archivo']);
        $this->assertSame('imprimible', $s01['documentos'][0]['inclusion']);
        $this->assertSame('CONTRATO-001', $this->seccion($data, 2)['documentos'][0]['registro']['num_resolucion']);
        $this->assertSame('2.2', $this->seccion($data, 2)['documentos'][0]['subseccion']['codigo']);
        $this->assertSame(['documentos' => 5, 'imprimibles' => 5, 'incidencias' => 0, 'folios' => 10, 'advertencias' => 0], $data['resumen']);
        $this->assertTrue($data['acceso_completo']);
        $this->assertTrue($data['integro']);
        // El orden inverso se puede revisar sin fijarlo todavía.
        $inverso = $this->getJson($this->url('desc'))->assertOk()->json('data');
        $this->assertSame([$ficha->id, $dni->id], array_column($this->seccion($inverso, 1)['documentos'], 'id'));
        $this->getJson($this->url('otro'))->assertUnprocessable();
    }

    public function test_only_incorporated_documents_of_this_legajo_and_scope_appear(): void
    {
        $this->documento('S01_B_DOCUMENTO_IDENTIDAD', 1, 1);
        // Fuera del alcance de la fase 1 (familiares): no se lista, pero la vista avisa que no cubre esos folios.
        $conyuge = $this->documento('S01_I_DNI_CONYUGE_CONCUBINO', 2, 2);
        // De otra persona y de un legajo anterior de la misma persona.
        $ajeno = $this->documento('S01_B_DOCUMENTO_IDENTIDAD', 1, 1, legajo: Legajo::factory()->create(['estado_legajo' => 'activo']));
        $anterior = $this->documento('FORM_TITULO_GRADO_SUPERIOR', 1, 1, legajo: Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'cerrado', 'fecha_apertura' => '2010-01-01']));
        // Entrega recibida por correo, aún no incorporada al legajo.
        RequerimientoDocumento::factory()->create(['usuario_id' => $this->persona->id, 'estado' => 'en_revision',
            'seguimiento' => [['accion' => 'respuesta', 'canal' => 'gmail', 'archivos' => [['archivo' => 'correo.pdf', 'ruta' => 'solicitudes/x/correo.pdf', 'disco' => 'documentos', 'hash' => 'h']]]]]);
        $respuesta = $this->getJson($this->url())->assertOk();
        foreach ([$conyuge->id, $ajeno->id, $anterior->id, 'solicitudes/', 'correo.pdf', 'S01_I_DNI_CONYUGE_CONCUBINO'] as $ausente) {
            $respuesta->assertDontSee($ausente, false);
        }
        $data = $respuesta->json('data');
        $this->assertSame(1, $data['resumen']['documentos']);
        // Folios fuera del alcance: se avisa, pero no es falta de acceso ni de integridad de lo mostrado.
        $this->assertContains('folios_no_incluidos', array_column($data['advertencias'], 'tipo'));
        $this->assertTrue($data['acceso_completo']);
        $this->assertTrue($data['integro']);
    }

    public function test_disabled_functionalities_and_active_types_without_documents_are_told_apart(): void
    {
        $certificado = $this->documento('EXP_CERTIFICADO_TRABAJO', 1, 2);
        ConfiguracionFuncionalidad::factory()->create(['codigo' => 's04.experiencia', 'activa' => false]);
        $data = $this->getJson($this->url())->assertOk()->assertDontSee($certificado->id, false)->json('data');
        $s04 = $this->seccion($data, 4);
        $this->assertSame('desactivada', $s04['estado']);
        $this->assertSame([], $s04['documentos']);
        $this->assertSame(['desactivada'], array_values(array_unique(array_column($s04['tipos'], 'estado'))));
        // Sección 02 activa sin documentos: cada tipo dice «sin documentos».
        $s02 = $this->seccion($data, 2);
        $this->assertSame('sin_documentos', $s02['estado']);
        $this->assertSame('sin_documentos', collect($s02['tipos'])->firstWhere('codigo', 'S02_H_REGISTRO_INDUCCION')['estado']);
        $this->assertContains(['tipo' => 'folios_no_incluidos', 'seccion' => 4, 'mensaje' => 'Sección 4: el legajo registra 2 folio(s) y esta vista incluye 0.'], $data['advertencias']);
    }

    public function test_sections_without_permission_are_omitted_without_describing_their_documents(): void
    {
        $dni = $this->documento('S01_B_DOCUMENTO_IDENTIDAD', 1, 2, ['nombre' => 'DNI reservado']);
        $this->documento('FORM_TITULO_GRADO_SUPERIOR', 1, 1);
        // El asistente ve el legajo, pero no los datos sensibles de la sección 01.
        $this->actuarComo(codigo: 'asistente_legajo');
        $respuesta = $this->getJson($this->url())->assertOk();
        foreach ([$dni->id, 'DNI reservado', 'S01_B_DOCUMENTO_IDENTIDAD', 'S01_A_FICHA_DATOS'] as $oculto) {
            $respuesta->assertDontSee($oculto, false);
        }
        $data = $respuesta->json('data');
        $this->assertSame(['id' => 1, 'nombre' => 'Información personal y familiar', 'estado' => 'omitida_permisos'], $this->seccion($data, 1));
        $this->assertFalse($data['acceso_completo']);
        $this->assertSame(1, $data['resumen']['documentos']);
        config(['permisos.roles.prueba_sin_legajo' => ['requerimientos.gestionar']]);
        $this->actuarComo(codigo: 'prueba_sin_legajo');
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_full_access_with_a_missing_file_is_not_an_intact_copy(): void
    {
        $this->documento('S01_B_DOCUMENTO_IDENTIDAD', 1, 2);
        $ausente = $this->documento('S02_F_CONTRATO', 1, 3, guardar: false);
        $this->documento('FORM_TITULO_GRADO_SUPERIOR', 1, 1);
        $data = $this->getJson($this->url())->assertOk()->json('data');
        $this->assertTrue($data['acceso_completo']);
        $this->assertFalse($data['integro']);
        $this->assertSame(1, $data['resumen']['incidencias']);
        $this->assertSame(2, $data['resumen']['imprimibles']);
        $s02 = $this->seccion($data, 2);
        // «Archivo no disponible» es distinto de «sin documentos» y de «desactivada»: la sección sigue con su documento y folios.
        $this->assertSame(['disponible', 1], [$s02['estado'], $s02['incidencias']]);
        $this->assertSame([$ausente->id, 1, 3, 'ausente', 'no_disponible'], [$s02['documentos'][0]['id'], $s02['documentos'][0]['folio_inicio'],
            $s02['documentos'][0]['folio_fin'], $s02['documentos'][0]['archivo']['estado'], $s02['documentos'][0]['inclusion']]);
        $this->assertSame('disponible', collect($s02['tipos'])->firstWhere('codigo', 'S02_F_CONTRATO')['estado']);
        $this->assertContains('archivo_ausente', array_column($data['advertencias'], 'tipo'));
        // Un registro sin archivo también impide reproducir el documento.
        DocumentoUsuario::factory()->create(['usuario_id' => $this->persona->id, 'legajo_id' => $this->legajo->id, 'archivo_id' => null,
            'tipo_documento_id' => TipoDocumento::query()->where('codigo', 'FORM_OTROS')->value('id'), 'seccion_legajo_id' => 3, 'estado' => 'pendiente']);
        $this->assertSame(2, $this->getJson($this->url())->json('data.resumen.incidencias'));
    }

    public function test_omitted_section_marks_partial_access_and_never_leaks_its_integrity_problems(): void
    {
        // Sección 01 con un archivo ausente, que el asistente no puede ver; el resto, íntegro.
        $dniAusente = $this->documento('S01_B_DOCUMENTO_IDENTIDAD', 1, 2, guardar: false);
        $this->documento('FORM_TITULO_GRADO_SUPERIOR', 1, 1);
        $this->actuarComo(codigo: 'asistente_legajo');
        $respuesta = $this->getJson($this->url())->assertOk()->assertDontSee($dniAusente->id, false)->assertDontSee('S01_B_DOCUMENTO_IDENTIDAD', false);
        $data = $respuesta->json('data');
        $this->assertFalse($data['acceso_completo']);
        $this->assertTrue($data['integro']);
        $this->assertSame(0, $data['resumen']['incidencias']);
        $this->assertSame(['omitida_permisos'], array_values(array_unique(array_column(array_filter($data['advertencias'], fn ($a) => $a['seccion'] === 1), 'tipo'))));
        $this->assertSame(['id' => 1, 'nombre' => 'Información personal y familiar', 'estado' => 'omitida_permisos'], $this->seccion($data, 1));
        // Quien sí ve la sección 01 recibe la incidencia.
        $this->actuarComo();
        $completa = $this->getJson($this->url())->assertOk()->json('data');
        $this->assertTrue($completa['acceso_completo']);
        $this->assertFalse($completa['integro']);
        $this->assertSame(1, $completa['resumen']['incidencias']);
    }

    public function test_corrected_and_annulled_documents_keep_their_folios_and_state(): void
    {
        $original = $this->documento('S02_F_CONTRATO', 1, 2);
        $nuevo = $this->documento('S02_F_CONTRATO', 3, 4, ['subsana_a_documento_id' => $original->id]);
        $anulado = $this->documento('S02_G_ADENDA', 5, 5, ['estado' => 'anulado']);
        $data = $this->getJson($this->url())->assertOk()->json('data');
        $documentos = collect($this->seccion($data, 2)['documentos'])->keyBy('id');
        $this->assertTrue($documentos[$original->id]['registro']['subsanado']);
        $this->assertSame('imprimible', $documentos[$original->id]['inclusion']);
        $this->assertTrue($documentos[$nuevo->id]['registro']['subsana_a_otro']);
        $this->assertSame('por_confirmar', $documentos[$anulado->id]['inclusion']);
        $this->assertSame([5, 5], [$documentos[$anulado->id]['folio_inicio'], $documentos[$anulado->id]['folio_fin']]);
        $this->assertSame([1, 3, 5], $documentos->pluck('folio_inicio')->values()->all());
        $this->assertContains('anulado', array_column($data['advertencias'], 'tipo'));
        $this->assertSame(2, $data['resumen']['imprimibles']);
    }

    public function test_missing_infected_and_page_mismatched_files_are_flagged_and_not_printable(): void
    {
        $ausente = $this->documento('FORM_TITULO_GRADO_SUPERIOR', 1, 1, guardar: false);
        $infectado = $this->documento('FORM_CAPACITACION', 2, 2, archivo: ['estado_antivirus' => 'infectado']);
        $descuadre = $this->documento('FORM_POSTGRADO', 3, 5, archivo: ['num_paginas' => 2]);
        $sinArchivo = DocumentoUsuario::factory()->create(['usuario_id' => $this->persona->id, 'legajo_id' => $this->legajo->id, 'archivo_id' => null,
            'tipo_documento_id' => TipoDocumento::query()->where('codigo', 'FORM_OTROS')->value('id'), 'seccion_legajo_id' => 3, 'estado' => 'pendiente']);
        $data = $this->getJson($this->url())->assertOk()->assertDontSee($sinArchivo->id, false)->json('data');
        $documentos = collect($this->seccion($data, 3)['documentos'])->keyBy('id');
        $this->assertSame(['ausente', 'no_disponible'], [$documentos[$ausente->id]['archivo']['estado'], $documentos[$ausente->id]['inclusion']]);
        $this->assertSame(['infectado', 'no_disponible'], [$documentos[$infectado->id]['archivo']['estado'], $documentos[$infectado->id]['inclusion']]);
        $this->assertSame('imprimible', $documentos[$descuadre->id]['inclusion']);
        $tipos = array_column($data['advertencias'], 'tipo');
        foreach (['archivo_ausente', 'archivo_infectado', 'folios_paginas', 'sin_archivo'] as $tipo) {
            $this->assertContains($tipo, $tipos);
        }
        $this->assertSame(['documentos' => 3, 'imprimibles' => 1, 'folios' => 5], array_intersect_key($data['resumen'], array_flip(['documentos', 'imprimibles', 'folios'])));
    }

    public function test_legacy_section_01_files_are_listed_apart_without_folios(): void
    {
        Storage::disk('local')->put('declaraciones/penal.pdf', "%PDF-1.4\n%%EOF");
        DeclaracionVerificacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'antecedente_penal', 'fecha' => '2020-05-01', 'anulado' => false, 'pdf_path' => 'declaraciones/penal.pdf', 'pdf_nombre' => 'penal.pdf']);
        DeclaracionVerificacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'antecedente_policial', 'fecha' => '2020-06-01', 'anulado' => false, 'pdf_path' => 'declaraciones/no-existe.pdf', 'pdf_nombre' => 'policial.pdf']);
        DeclaracionVerificacion::factory()->create(['usuario_id' => $this->persona->id, 'tipo' => 'djbr', 'anulado' => true, 'pdf_path' => 'declaraciones/anulada.pdf']);
        $data = $this->getJson($this->url())->assertOk()->json('data');
        $sinFoliar = $this->seccion($data, 1)['sin_foliar'];
        $this->assertCount(2, $sinFoliar);
        $this->assertSame('01_informacion_personal_y_familiar/SF01_S01_C_ANTECEDENTES_PENALES.pdf', $sinFoliar[0]['nombre_zip']);
        $this->assertSame(['imprimible', 'no_disponible'], array_column($sinFoliar, 'inclusion'));
        $this->assertArrayNotHasKey('folio_inicio', $sinFoliar[0]);
        $this->assertSame(0, $data['resumen']['folios']);
    }

    public function test_a_person_without_legajo_has_no_preview(): void
    {
        $sinLegajo = Usuario::factory()->create();
        $this->getJson('/api/v1/expedientes/'.$sinLegajo->id.'/expediente-imprimible')->assertStatus(409);
    }
}
