<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\ReconocimientoTitulo;
use Database\Seeders\Seccion03CatalogoSeeder;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class ReconocimientoTituloTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    private TipoDocumento $tipo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('legajo.disco_documentos'));
        $this->seed([SeccionesLegajoCatalogoSeeder::class, Seccion03CatalogoSeeder::class]);
        $this->persona = Usuario::factory()->create(['nombres' => 'Carlos Alexis', 'apellido_paterno' => 'Benedicto', 'apellido_materno' => 'Jiménez']);
        Legajo::factory()->create(['usuario_id' => $this->persona->id]);
        $this->tipo = TipoDocumento::query()->where('codigo', 'FORM_TITULO_GRADO_SUPERIOR')->firstOrFail();
        $this->actuarComo();
    }

    private function url(string $accion = 'reconocer'): string
    {
        return '/api/v1/expedientes/'.$this->persona->id.'/seccion-03/'.$accion;
    }

    private function transcripcion(): string
    {
        return "La Universidad Nacional Autónoma\nde México\notorga a\nCarlos Alexis Benedicto Jiménez\nel título de\nlicenciado en ciencia política\nen atención a que demostró tener hechos los estudios\ny haber sido aprobado en el examen\nprofesional que sustentó el día 15 de agosto\ndel 2023, según constancia archivada en la misma\nuniversidad.\nPor mi Raza hablará el Espíritu\nDado en la Ciudad de México el día 20 de\nOctubre de 2023.\nEl Rector\nDr. Enrique Graue Wiechers";
    }

    private function lecturaSimulada(): void
    {
        $this->partialMock(ReconocimientoTitulo::class, function ($mock): void {
            $mock->shouldReceive('extraer')->andReturn(['texto' => $this->transcripcion(), 'paginas' => 1, 'metodo' => 'ocr_local']);
        });
    }

    private function guardar(string $token, ?UploadedFile $pdf = null)
    {
        return $this->post($this->url('documentos'), [
            'tipo_documento_id' => $this->tipo->id, 'nombre' => 'Licenciado en Ciencia Política',
            'emisor' => 'Universidad Nacional Autónoma de México', 'fecha_emision' => '2023-10-20',
            'nivel' => 'titulo_profesional', 'pais' => 'México', 'numero_diploma' => 'DIP-PRUEBA', 'fecha_obtencion' => '2023-10-20',
            'folio_inicio' => 1, 'folio_fin' => 1, 'modalidad_presentacion' => 'copia_simple',
            'reconocimiento_token' => $token, 'reconocimiento_confirmado' => true, 'pdf' => $pdf ?? $this->pdf(),
        ], ['Accept' => 'application/json']);
    }

    public function test_mexican_title_extracts_holder_institution_and_issue_date_without_using_exam_date(): void
    {
        $resultado = app(ReconocimientoTitulo::class)->analizar($this->transcripcion());
        $this->assertSame('Carlos Alexis Benedicto Jiménez', $resultado['campos']['titular_nombre']);
        $this->assertSame('Universidad Nacional Autónoma de México', $resultado['campos']['emisor']);
        $this->assertSame('licenciado en ciencia política', $resultado['campos']['nombre']);
        $this->assertSame('titulo_profesional', $resultado['campos']['nivel']);
        $this->assertSame('2023-10-20', $resultado['campos']['fecha_emision']);
        $this->assertSame('México', $resultado['campos']['pais']);
        $this->assertContains('numero_diploma', $resultado['faltantes']);
        $this->assertContains('numero_registro', $resultado['faltantes']);
        $this->assertCount(2, $resultado['fechas_detectadas']);
    }

    public function test_gothic_header_errors_and_joined_title_labels_do_not_hide_holder_or_institution(): void
    {
        $texto = "La Univergidad Nacional Autónoma\nde México\notorga a\nCarlos Alexis Benedicto Jiménez\neltitulode\nlicenciado en ciencia política\nen atención a que realizó los estudios\nDado en la Ciudad de México el día 20 de octubre de 2023";
        $campos = app(ReconocimientoTitulo::class)->analizar($texto)['campos'];
        $this->assertSame('Carlos Alexis Benedicto Jiménez', $campos['titular_nombre']);
        $this->assertSame('La Univergidad Nacional Autónoma de México', 'La '.$campos['emisor']);
        $this->assertSame('México', $campos['pais']);
        $this->assertSame('licenciado en ciencia política', $campos['nombre']);
    }

    public function test_holder_can_be_found_when_granting_label_was_not_read_but_title_label_is_present(): void
    {
        $campos = app(ReconocimientoTitulo::class)->analizar("Universidad de Ejemplo\nCarlos Alexis Benedicto Jiménez\nel título de\nLicenciado en Ciencia Política")['campos'];
        $this->assertSame('Carlos Alexis Benedicto Jiménez', $campos['titular_nombre']);
        $sinTitular = app(ReconocimientoTitulo::class)->analizar("Universidad de Ejemplo\nel título de\nLicenciado en Ciencia Política")['campos'];
        $this->assertArrayNotHasKey('titular_nombre', $sinTitular);
    }

    public function test_alternative_layout_is_selected_only_when_it_recovers_more_structured_data(): void
    {
        $lector = app(ReconocimientoTitulo::class);
        $parcial = "licenciado en ciencia política\nDado en la Ciudad de México el día 20 de octubre de 2023";
        $this->assertSame($this->transcripcion(), $lector->mejorLectura($parcial, $this->transcripcion()));
        $this->assertSame($this->transcripcion(), $lector->mejorLectura($this->transcripcion(), 'ruido ilegible'));
    }

    public function test_blank_basic_studies_form_does_not_invent_student_school_or_issue_date(): void
    {
        $texto = "MINISTERIO DE EDUCACIÓN\nCERTIFICADO OFICIAL DE ESTUDIOS\nEDUCACIÓN BÁSICA ALTERNATIVA\nCICLO AVANZADO\nREPÚBLICA DEL PERÚ\nPerteneciente a: ...................\nNOMBRES: ...............\nCEBA: ...................\nLugar y fecha de expedición: ...............\nSERIE H N° 097201\nSERIE H N° 097201\nGRADOS DE ESTUDIOS\nRESPONSABLE DEL LLENADO";
        $resultado = app(ReconocimientoTitulo::class)->analizar($texto);
        $campos = $resultado['campos'];
        $this->assertSame('educacion_basica', $campos['nivel']);
        $this->assertSame('Certificado Oficial de Estudios — Educación Básica Alternativa — Ciclo Avanzado', $campos['nombre']);
        $this->assertSame('H-097201', $campos['numero_diploma']);
        $this->assertSame('Perú', $campos['pais']);
        foreach (['titular_nombre', 'emisor', 'fecha_emision', 'numero_registro'] as $campo) {
            $this->assertArrayNotHasKey($campo, $campos);
        }
        $this->assertStringContainsString('formato sin completar', implode(' ', $resultado['avisos']));
    }

    public function test_basic_certificate_keeps_conflicting_serials_pending_and_requires_actual_school_name(): void
    {
        $texto = "CERTIFICADO DE ESTUDIOS\nEDUCACIÓN BÁSICA REGULAR\nSECUNDARIA\nApellidos y nombres: Carlos Alexis Benedicto Jiménez\nInstitución Educativa: Colegio de Prueba\nFecha de emisión: 20/10/2023\nSERIE H N° 000123\nSERIE H N° 000124";
        $campos = app(ReconocimientoTitulo::class)->analizar($texto)['campos'];
        $this->assertSame('Colegio de Prueba', $campos['emisor']);
        $this->assertSame('Carlos Alexis Benedicto Jiménez', $campos['titular_nombre']);
        $this->assertSame('2023-10-20', $campos['fecha_emision']);
        $this->assertArrayNotHasKey('numero_diploma', $campos);
        $this->assertStringNotContainsString('Oficial', $campos['nombre']);
    }

    public function test_basic_studies_scanning_and_confirmed_save_use_the_existing_academic_record(): void
    {
        $this->persona->update(['exige_estudios_basicos' => true]);
        $this->tipo = TipoDocumento::query()->where('codigo', 'FORM_ESTUDIOS_BASICOS')->firstOrFail();
        $this->partialMock(ReconocimientoTitulo::class, function ($mock): void {
            $mock->shouldReceive('extraer')->andReturn(['texto' => "CERTIFICADO OFICIAL DE ESTUDIOS\nEDUCACIÓN BÁSICA ALTERNATIVA\nCICLO AVANZADO\nREPÚBLICA DEL PERÚ\nCEBA: Centro Educativo de Prueba\nApellidos y nombres: Carlos Alexis Benedicto Jiménez\nFecha de emisión: 20/10/2023\nSERIE H N° 000123", 'paginas' => 1, 'metodo' => 'ocr_local']);
        });
        $data = $this->post($this->url(), ['tipo_documento_id' => $this->tipo->id, 'archivo' => $this->pdf()], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame('educacion_basica', $data['campos']['nivel']);
        $id = $this->post($this->url('documentos'), [
            'tipo_documento_id' => $this->tipo->id, 'nombre' => $data['campos']['nombre'], 'emisor' => $data['campos']['emisor'],
            'fecha_emision' => $data['campos']['fecha_emision'], 'nivel' => 'educacion_basica', 'pais' => 'Perú',
            'numero_diploma' => 'H-000123', 'folio_inicio' => 1, 'folio_fin' => 1, 'modalidad_presentacion' => 'copia_simple',
            'reconocimiento_token' => $data['token'], 'reconocimiento_confirmado' => true, 'pdf' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('formacion_academica', ['documento_id' => $id, 'nivel' => 'educacion_basica', 'numero_diploma' => 'H-000123', 'numero_registro_sunedu' => null]);
        $this->assertFalse(DocumentoUsuario::query()->findOrFail($id)->fiscalizado);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'formacion.reconocimiento.confirmado']);
    }

    public function test_agreement_and_end_of_studies_dates_are_not_diploma_number_or_issue_date(): void
    {
        $texto = "La Universidad Tecnológica\nde México\nOtorga a\nBrian Cesar Romero Mendoza\nel Título de\nLicenciado en Ingeniería Industrial y de\nSistemas\nCon reconocimiento de validez oficial de estudios\npor Acuerdo Secretarial número 142 publicado en el\nDiario Oficial de la Federación el 24 de octubre de\n1988, en atención a que terminó los estudios\ncorrespondientes el día 17 de diciembre de 2018\nEcatepec de Morelos, Estado de México a 31 de mayo de 2022\nEl Rector";
        $campos = app(ReconocimientoTitulo::class)->analizar($texto)['campos'];
        $this->assertSame('2022-05-31', $campos['fecha_emision']);
        $this->assertSame('Licenciado en Ingeniería Industrial y de Sistemas', $campos['nombre']);
        $this->assertArrayNotHasKey('numero_diploma', $campos);
        $this->assertArrayNotHasKey('numero_registro', $campos);
    }

    public function test_explicit_registration_and_diploma_are_separate_and_invalid_dates_are_not_invented(): void
    {
        $campos = app(ReconocimientoTitulo::class)->analizar("Instituto Superior del Perú\nTítulo de\nPROFESIONAL TÉCNICO EN COMPUTACIÓN\nExpedido\nFecha de emisión: 31/01/2025\nDiploma número DIP-123\nCódigo MINEDU: J00202500001")['campos'];
        $this->assertSame('2025-01-31', $campos['fecha_emision']);
        $this->assertSame('DIP-123', $campos['numero_diploma']);
        $this->assertSame('J00202500001', $campos['numero_registro']);
        $this->assertSame('tecnico', $campos['nivel']);
        $vacio = app(ReconocimientoTitulo::class)->analizar('Imagen borrosa sin campos reconocibles 31 de febrero de 2023');
        $this->assertSame([], $vacio['campos']);
    }

    public function test_recognition_is_read_only_and_warns_when_the_holder_does_not_match(): void
    {
        $this->lecturaSimulada();
        $this->persona->update(['nombres' => 'Otra Persona']);
        $data = $this->post($this->url(), ['tipo_documento_id' => $this->tipo->id, 'archivo' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.campos.nombre', 'licenciado en ciencia política')->json('data');
        $this->assertStringContainsString('no coincide', implode(' ', $data['avisos']));
        $this->assertDatabaseCount('documentos_usuario', 0);
        $this->assertDatabaseCount('formacion_academica', 0);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_confirmed_reading_preserves_corrections_and_provenance_without_certifying_authenticity(): void
    {
        $this->lecturaSimulada();
        $token = $this->post($this->url(), ['tipo_documento_id' => $this->tipo->id, 'archivo' => $this->pdf()], ['Accept' => 'application/json'])->assertOk()->json('data.token');
        $id = $this->guardar($token)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('formacion_academica', ['documento_id' => $id, 'pais' => 'México', 'numero_diploma' => 'DIP-PRUEBA', 'es_revalidado' => false]);
        $this->assertFalse(DocumentoUsuario::query()->findOrFail($id)->fiscalizado);
        $auditoria = AuditoriaEvento::query()->where('entidad_id', $id)->where('accion', 'formacion.reconocimiento.confirmado')->firstOrFail();
        $this->assertSame('licenciado en ciencia política', json_decode($auditoria->datos_antes, true)['campos']['nombre']);
        $this->assertSame('Licenciado en Ciencia Política', json_decode($auditoria->datos_despues, true)['nombre']);
        $this->assertTrue(json_decode($auditoria->datos_despues, true)['revision_humana']);
    }

    public function test_changed_file_tampered_expired_or_foreign_reading_cannot_be_saved(): void
    {
        $this->lecturaSimulada();
        $token = $this->post($this->url(), ['tipo_documento_id' => $this->tipo->id, 'archivo' => $this->pdf()], ['Accept' => 'application/json'])->assertOk()->json('data.token');
        $this->guardar($token, $this->pdf(relleno: 20))->assertUnprocessable()->assertJsonValidationErrors('reconocimiento_token');
        $this->guardar('token-falso')->assertUnprocessable();
        $original = json_decode(Crypt::decryptString($token), true);
        foreach (['usuario' => fake()->uuid(), 'actor' => fake()->uuid(), 'institucion' => 'otra', 'tipo' => fake()->uuid(), 'vence' => now()->subMinute()->timestamp] as $campo => $valor) {
            $otro = Crypt::encryptString(json_encode([...$original, $campo => $valor]));
            $this->guardar($otro)->assertUnprocessable()->assertJsonValidationErrors('reconocimiento_token');
        }
        $this->assertDatabaseCount('documentos_usuario', 0);
    }

    public function test_access_validation_and_sensitive_type_rules_apply_before_running_ocr(): void
    {
        $this->mock(ReconocimientoTitulo::class)->shouldNotReceive('extraer');
        $this->post($this->url(), ['tipo_documento_id' => $this->tipo->id, 'archivo' => UploadedFile::fake()->createWithContent('falso.pdf', 'no es pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->tipo->update(['es_dato_sensible' => true]);
        $this->post($this->url(), ['tipo_documento_id' => $this->tipo->id, 'archivo' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden();
        $this->actuarComo(codigo: 'consulta');
        $this->post($this->url(), ['tipo_documento_id' => $this->tipo->id, 'archivo' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden();
    }

    private function pdfConObjetos(array $objetos): UploadedFile
    {
        $contenido = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objetos as $i => $objeto) {
            $offsets[] = strlen($contenido);
            $contenido .= ($i + 1)." 0 obj\n".$objeto."\nendobj\n";
        }
        $xref = strlen($contenido);
        $contenido .= 'xref'."\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $contenido .= sprintf("%010d 00000 n \n", $offset);
        }
        $contenido .= 'trailer << /Size '.(count($objetos) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";

        return UploadedFile::fake()->createWithContent('titulo.pdf', $contenido);
    }

    public function test_digital_pdf_is_read_without_ocr_and_without_retaining_temporary_files(): void
    {
        $texto = 'BT /F1 14 Tf 50 780 Td (Universidad de Prueba del Peru) Tj 0 -30 Td (Otorga a Persona de Prueba el titulo de) Tj 0 -30 Td (Licenciado en Ingenieria Industrial) Tj 0 -30 Td (Dado en Lima el 20 de octubre de 2023) Tj ET';
        $pdf = $this->pdfConObjetos([
            '<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($texto).">>\nstream\n".$texto."\nendstream",
        ]);
        $antes = glob(sys_get_temp_dir().'/titulo-*');
        $lectura = app(ReconocimientoTitulo::class)->extraer($pdf);
        $this->assertSame('texto_pdf', $lectura['metodo']);
        $this->assertStringContainsString('Licenciado', $lectura['texto']);
        $this->assertSame($antes, glob(sys_get_temp_dir().'/titulo-*'));
    }

    public function test_scanned_pdf_uses_local_ocr_instead_of_requiring_a_text_layer(): void
    {
        $imagen = imagecreatetruecolor(2100, 1260);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 255, 255, 255));
        $base = imagecreatetruecolor(700, 420);
        imagefill($base, 0, 0, imagecolorallocate($base, 255, 255, 255));
        $negro = imagecolorallocate($base, 0, 0, 0);
        foreach (['Universidad de Prueba del Peru', 'Otorga a Persona de Prueba', 'el titulo de', 'Licenciado en Ingenieria Industrial', 'Dado en Lima el 20 de octubre de 2023'] as $i => $linea) {
            imagestring($base, 5, 25, 30 + $i * 45, $linea, $negro);
        }
        imagecopyresampled($imagen, $base, 0, 0, 0, 0, 2100, 1260, 700, 420);
        ob_start();
        imagejpeg($imagen, null, 90);
        $jpeg = ob_get_clean();
        $comando = 'q 500 0 0 300 40 450 cm /Im1 Do Q';
        $pdf = $this->pdfConObjetos([
            '<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /XObject /Subtype /Image /Width 2100 /Height 1260 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg).">>\nstream\n".$jpeg."\nendstream",
            '<< /Length '.strlen($comando).">>\nstream\n".$comando."\nendstream",
        ]);
        $lectura = app(ReconocimientoTitulo::class)->extraer($pdf);
        $this->assertSame('ocr_local', $lectura['metodo']);
        $this->assertStringContainsString('Licenciado', $lectura['texto']);
    }

    public function test_more_than_five_pages_are_rejected_and_temporary_files_are_cleaned_up(): void
    {
        $paginas = [];
        for ($i = 0; $i < 6; $i++) {
            $paginas[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>';
        }
        $pdf = $this->pdfConObjetos(['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R 4 0 R 5 0 R 6 0 R 7 0 R 8 0 R] /Count 6 >>', ...$paginas]);
        $antes = glob(sys_get_temp_dir().'/titulo-*');
        $this->post($this->url(), ['tipo_documento_id' => $this->tipo->id, 'archivo' => $pdf], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('archivo');
        $this->assertSame($antes, glob(sys_get_temp_dir().'/titulo-*'));
    }

    public function test_local_ocr_reads_a_real_raster_image(): void
    {
        if (! is_executable('/usr/bin/tesseract')) {
            $this->markTestSkipped('El contenedor todavía no tiene Tesseract.');
        }
        $imagen = imagecreatetruecolor(700, 420);
        $blanco = imagecolorallocate($imagen, 255, 255, 255);
        $negro = imagecolorallocate($imagen, 0, 0, 0);
        imagefill($imagen, 0, 0, $blanco);
        foreach (['Universidad de Prueba del Peru', 'Otorga a', 'Persona de Prueba', 'el titulo de', 'Licenciado en Ingenieria Industrial', 'Dado en Lima el 20 de octubre de 2023'] as $i => $linea) {
            imagestring($imagen, 5, 25, 30 + $i * 45, $linea, $negro);
        }
        $grande = imagescale($imagen, 2100, 1260);
        ob_start();
        imagepng($grande);
        $contenido = ob_get_clean();
        $archivo = UploadedFile::fake()->createWithContent('titulo.png', $contenido);
        $resultado = app(ReconocimientoTitulo::class)->extraer($archivo);
        $this->assertSame('ocr_local', $resultado['metodo']);
        $this->assertStringContainsString('Licenciado', $resultado['texto']);
        $this->assertSame('titulo_profesional', app(ReconocimientoTitulo::class)->analizar($resultado['texto'])['campos']['nivel']);
    }
}
