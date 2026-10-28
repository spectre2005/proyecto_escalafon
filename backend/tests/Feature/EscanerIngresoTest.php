<?php

namespace Tests\Feature;

use App\Support\Documentos\EscanerIngreso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class EscanerIngresoTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private const TEXTO = "CONTRATO N. 015-2026-UNIQ\nProceso de seleccion: CAS N. 003-2026-UNIQ\nResultados finales publicados el 20/10/2026.\nEmisor: UNIVERSIDAD DE EJEMPLO\nFecha de emision: 21/10/2026.";

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['escaner.url' => 'http://ollama:11434', 'escaner.modelo' => 'qwen3:1.7b']);
    }

    private function propuestas(): array
    {
        return [
            'numero_documento_vinculo' => ['valor' => '015-2026-UNIQ', 'evidencia' => 'CONTRATO N. 015-2026-UNIQ'],
            'proceso_seleccion' => ['valor' => 'CAS N. 003-2026-UNIQ', 'evidencia' => 'Proceso de seleccion: CAS N. 003-2026-UNIQ'],
            'fecha_resultado_seleccion' => ['valor' => '2026-10-20', 'evidencia' => 'Resultados finales publicados el 20/10/2026.'],
            'emisor' => ['valor' => 'UNIVERSIDAD DE EJEMPLO', 'evidencia' => 'Emisor: UNIVERSIDAD DE EJEMPLO'],
            'fecha_emision' => ['valor' => '2026-10-21', 'evidencia' => 'Fecha de emision: 21/10/2026.'],
        ];
    }

    public function test_it_returns_only_supported_proposals_with_evidence_without_creating_records(): void
    {
        $this->actuarComo();
        $this->travelTo(now()->setDate(2026, 10, 27));
        $this->partialMock(EscanerIngreso::class, fn ($mock) => $mock->shouldReceive('leerTexto')->once()->andReturn(self::TEXTO));
        Http::fake(['ollama:11434/api/chat' => Http::response(['done' => true, 'message' => ['content' => json_encode($this->propuestas())]])]);
        $this->post('/api/v1/documento-ingreso/escanear', ['pdf' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(3, 'campos')->assertJsonPath('campos.0.valor', '015-2026-UNIQ');
        Http::assertSent(fn ($request): bool => $request['stream'] === false && $request['think'] === false && $request['format']['additionalProperties'] === false);
        $this->assertDatabaseCount('legajos', 0);
        $this->assertDatabaseCount('vinculos_laborales', 0);
        $this->assertDatabaseCount('documentos_usuario', 0);
    }

    public function test_availability_checks_the_configured_model_without_exposing_infrastructure(): void
    {
        $this->actuarComo();
        Http::fake(['ollama:11434/api/tags' => Http::response(['models' => [['name' => 'qwen3:1.7b']]])]);
        $this->getJson('/api/v1/documento-ingreso/escaner')->assertOk()->assertJsonPath('disponible', true)->assertDontSee('11434');
    }

    public function test_missing_model_is_reported_as_unavailable(): void
    {
        $this->actuarComo();
        Http::fake(['ollama:11434/api/tags' => Http::response(['models' => []])]);
        $this->getJson('/api/v1/documento-ingreso/escaner')->assertOk()->assertJsonPath('disponible', false);
    }

    public function test_it_rejects_invented_values_and_emission_dates_used_as_selection_results(): void
    {
        $datos = $this->propuestas();
        $datos['numero_documento_vinculo']['valor'] = '999-2026';
        $datos['proceso_seleccion']['evidencia'] = 'Una cita que no existe en el PDF';
        $datos['fecha_resultado_seleccion'] = $datos['fecha_emision'];
        $datos['email'] = ['valor' => 'inventado@example.test', 'evidencia' => self::TEXTO];
        $this->travelTo(now()->setDate(2026, 10, 27));
        $resultado = app(EscanerIngreso::class)->validarPropuestas($datos, self::TEXTO);
        $this->assertSame([], array_column($resultado['campos'], 'clave'));
    }

    public function test_dates_are_normalized_only_when_they_match_document_evidence(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27));
        $datos = $this->propuestas();
        $datos['fecha_resultado_seleccion']['valor'] = '20/10/2026';
        $datos['fecha_emision']['valor'] = '31/02/2026';
        $resultado = app(EscanerIngreso::class)->validarPropuestas($datos, self::TEXTO);
        $valores = array_column($resultado['campos'], 'valor', 'clave');
        $this->assertSame('2026-10-20', $valores['fecha_resultado_seleccion']);
        $this->assertArrayNotHasKey('fecha_emision', $valores);
    }

    public function test_failures_are_reported_without_leaking_provider_details(): void
    {
        $this->actuarComo();
        $this->partialMock(EscanerIngreso::class, fn ($mock) => $mock->shouldReceive('leerTexto')->once()->andReturn(self::TEXTO));
        Http::fake(['ollama:11434/api/chat' => Http::response(['error' => 'private provider detail'], 500)]);
        $this->post('/api/v1/documento-ingreso/escanear', ['pdf' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertStatus(503)->assertDontSee('private provider detail');
    }

    public function test_it_requires_permissions_before_contacting_ollama(): void
    {
        config(['permisos.roles.sin_escaner' => ['usuarios.gestionar']]);
        $this->actuarComo(null, 'sin_escaner');
        $this->post('/api/v1/documento-ingreso/escanear', ['pdf' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_an_invalid_pdf_is_rejected_before_reading_or_calling_ollama(): void
    {
        $this->actuarComo();
        $this->post('/api/v1/documento-ingreso/escanear', ['pdf' => UploadedFile::fake()->createWithContent('falso.pdf', 'esto no es un PDF')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('pdf');
        Http::assertNothingSent();
    }

    public function test_malformed_or_truncated_model_responses_are_not_applied(): void
    {
        foreach ([['done' => true, 'message' => ['content' => 'no es JSON']], ['done' => true, 'done_reason' => 'length', 'message' => ['content' => '{}']]] as $respuesta) {
            Http::fake(['ollama:11434/api/chat' => Http::response($respuesta)]);
            try {
                app(EscanerIngreso::class)->interpretar(self::TEXTO);
                $this->fail('Debe rechazar una respuesta incompleta.');
            } catch (HttpException $error) {
                $this->assertSame(503, $error->getStatusCode());
            }
        }
    }

    public function test_it_does_not_send_documents_to_remote_servers(): void
    {
        config(['escaner.url' => 'https://example.com']);
        $this->assertFalse(app(EscanerIngreso::class)->estado()['disponible']);
        Http::assertNothingSent();
    }

    public function test_local_ocr_reads_an_image_only_pdf(): void
    {
        $imagen = imagecreatetruecolor(1600, 500);
        $blanco = imagecolorallocate($imagen, 255, 255, 255);
        $negro = imagecolorallocate($imagen, 0, 0, 0);
        imagefill($imagen, 0, 0, $blanco);
        imagestring($imagen, 5, 50, 50, 'CONTRATO 015-2026-UNIQ', $negro);
        imagestring($imagen, 5, 50, 100, 'Proceso de seleccion CAS 003-2026-UNIQ', $negro);
        $grande = imagescale($imagen, 3200, 1000);
        ob_start();
        imagejpeg($grande, null, 95);
        $jpeg = ob_get_clean();
        imagedestroy($imagen);
        imagedestroy($grande);
        $stream = 'q 600 0 0 187 0 500 cm /Img Do Q';
        $contenido = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            ."2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            ."3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Img 4 0 R >> >> /Contents 5 0 R >> endobj\n"
            .'4 0 obj << /Type /XObject /Subtype /Image /Width 3200 /Height 1000 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg)." >> stream\n".$jpeg."\nendstream endobj\n"
            .'5 0 obj << /Length '.strlen($stream)." >> stream\n".$stream."\nendstream endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
        $pdf = UploadedFile::fake()->createWithContent('escaneado-ficticio.pdf', $contenido);
        $texto = app(EscanerIngreso::class)->leerTexto($pdf);
        $this->assertStringContainsString('015-2026-UNIQ', $texto);
        $this->assertStringContainsString('003-2026', $texto);
        Http::assertNothingSent();
    }

    public function test_impossible_or_future_selection_dates_are_not_applied(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27));
        foreach (['2026-02-31', '2099-01-01'] as $fecha) {
            $texto = 'Resultados finales publicados el '.$fecha.'.';
            $resultado = app(EscanerIngreso::class)->validarPropuestas([
                'fecha_resultado_seleccion' => ['valor' => $fecha, 'evidencia' => $texto],
            ], $texto);
            $this->assertSame([], $resultado['campos']);
        }
    }

    public function test_it_focuses_long_documents_without_losing_selection_results(): void
    {
        $texto = "CONTRATO N. 015-2026-UNIQ\n".str_repeat("Clausula sobre obligaciones y condiciones contractuales.\n", 150)
            ."Proceso de seleccion: CAS N. 003-2026-UNIQ\nResultados finales publicados el 20/10/2026.";
        $this->travelTo(now()->setDate(2026, 10, 27));
        Http::fake(['ollama:11434/api/chat' => Http::response(['done' => true, 'message' => ['content' => json_encode($this->propuestas())]])]);
        $resultado = app(EscanerIngreso::class)->interpretar($texto);
        $this->assertSame('2026-10-20', array_column($resultado['campos'], 'valor', 'clave')['fecha_resultado_seleccion']);
        Http::assertSent(fn ($request): bool => mb_strlen($request['messages'][1]['content']) < 3400
            && str_contains($request['messages'][1]['content'], 'Resultados finales publicados el 20/10/2026.'));
    }

    public function test_a_blank_pdf_reports_a_reading_problem_without_calling_the_model(): void
    {
        try {
            app(EscanerIngreso::class)->leerTexto($this->pdf());
            $this->fail('Debe informar que el PDF no contiene texto legible.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('no contiene texto legible', $error->errors()['pdf'][0]);
        }
        Http::assertNothingSent();
    }

    public function test_a_blank_template_and_legal_references_do_not_fill_the_fields(): void
    {
        $texto = "CONTRATO ADMINISTRATIVO DE SERVICIOS N°000-2020-EJEMPLO\nBase legal: Decreto Legislativo 1057. Regimen CAS. Resolucion de Alcaldia N 17.\nInicio del contrato: 18/06/2019.";
        $datos = [
            'numero_documento_vinculo' => ['valor' => '000-2020-EJEMPLO', 'evidencia' => 'CONTRATO ADMINISTRATIVO DE SERVICIOS N°000-2020-EJEMPLO'],
            'proceso_seleccion' => ['valor' => 'CAS', 'evidencia' => 'Regimen CAS.'],
            'fecha_resultado_seleccion' => ['valor' => '2019-06-18', 'evidencia' => 'Inicio del contrato: 18/06/2019.'],
        ];
        $resultado = app(EscanerIngreso::class)->validarPropuestas($datos, $texto);
        $this->assertSame([], $resultado['campos']);
        $this->assertCount(3, $resultado['observaciones']);
        $datos['numero_documento_vinculo'] = ['valor' => '1057', 'evidencia' => 'Base legal: Decreto Legislativo 1057.'];
        $this->assertSame([], app(EscanerIngreso::class)->validarPropuestas($datos, $texto)['campos']);
        $datos['numero_documento_vinculo'] = ['valor' => '17', 'evidencia' => 'Resolucion de Alcaldia N 17.'];
        $this->assertSame([], app(EscanerIngreso::class)->validarPropuestas($datos, $texto)['campos']);
    }

    public function test_number_spacing_from_ocr_does_not_reject_the_main_resolution(): void
    {
        $texto = "RESOLUCION SECRETARIA GENERAL N* 048 -2014/DP-SG\nLima, 25 agosto 2014";
        $resultado = app(EscanerIngreso::class)->validarPropuestas([
            'numero_documento_vinculo' => ['valor' => '048-2014/DP-SG', 'evidencia' => 'RESOLUCION SECRETARIA GENERAL N* 048-2014/DP-SG'],
        ], $texto);
        $this->assertSame('048-2014/DP-SG', $resultado['campos'][0]['valor'] ?? null);
    }

    public function test_a_clear_primary_heading_overrides_a_cited_resolution(): void
    {
        $texto = "RESOLUCION SECRETARIA GENERAL N* 048 -2014/DP-SG\nLima, 25 agosto 2014\nMediante Resolucion Defensorial N* 0012-2011/DP se aprobo el reglamento.";
        Http::fake(['ollama:11434/api/chat' => Http::response(['done' => true, 'message' => ['content' => json_encode([
            'numero_documento_vinculo' => ['valor' => '0012-2011/DP', 'evidencia' => 'Mediante Resolucion Defensorial N* 0012-2011/DP se aprobo el reglamento.'],
            'proceso_seleccion' => null, 'fecha_resultado_seleccion' => null,
        ])]])]);
        $resultado = app(EscanerIngreso::class)->interpretar($texto);
        $this->assertSame('048-2014/DP-SG', $resultado['campos'][0]['valor']);
        $this->assertCount(1, $resultado['campos']);
    }

    public function test_a_damaged_heading_does_not_take_the_number_of_the_following_memorandum(): void
    {
        $texto = 'RESOLUCION SECRETARIA GENERAL N* 0 4Yf -2014/DP-SG Visto, el Memorando N* 620-2014-DP/OGDH';
        Http::fake(['ollama:11434/api/chat' => Http::response(['done' => true, 'message' => ['content' => '{}']])]);
        $this->assertSame([], app(EscanerIngreso::class)->interpretar($texto)['campos']);
    }

    public function test_a_damaged_first_heading_does_not_drop_a_leading_zero(): void
    {
        $texto = "RESOLUCION SECRETARIA GENERAL N* 0 4Yf -2014/DP-SG\nRESOLUCION SECRETARIA GENERAL N* 48-2014/DP-SG";
        Http::fake(['ollama:11434/api/chat' => Http::response(['done' => true, 'message' => ['content' => '{}']])]);
        $this->assertSame([], app(EscanerIngreso::class)->interpretar($texto)['campos']);
    }

    public function test_the_real_uploaded_resolution_returns_its_number_only(): void
    {
        if (getenv('OLLAMA_RESOLUCION_REAL') !== '1') {
            $this->markTestSkipped('Prueba local opcional de extracción del segundo PDF proporcionado.');
        }
        Http::allowStrayRequests();
        $resultado = app(EscanerIngreso::class)->escanear(new UploadedFile('/tmp/resolucion-revision.pdf', 'resolucion.pdf', 'application/pdf', null, true));
        $this->assertSame('048-2014/DP-SG', array_column($resultado['campos'], 'valor', 'clave')['numero_documento_vinculo'] ?? null, json_encode($resultado));
        $this->assertCount(1, $resultado['campos'], json_encode($resultado));
    }

    public function test_ocr_reads_the_uploaded_secretary_resolution(): void
    {
        if (getenv('OCR_RESOLUCION_REAL') !== '1') {
            $this->markTestSkipped('Prueba opcional del segundo PDF proporcionado.');
        }
        $servicio = app(EscanerIngreso::class);
        $texto = $servicio->leerTexto(new UploadedFile('/tmp/resolucion-revision.pdf', 'resolucion.pdf', 'application/pdf', null, true));
        fwrite(STDOUT, json_encode($servicio->resumenLectura()).PHP_EOL);
        $this->assertStringContainsString('048', $texto);
        $this->assertStringContainsString('/DP-SG', $texto);
    }

    public function test_enhanced_ocr_reads_the_blurry_user_pdf(): void
    {
        if (getenv('OCR_PDF_FORMATO') !== '1') {
            $this->markTestSkipped('Prueba opcional de OCR sobre la copia temporal del PDF proporcionado.');
        }
        $servicio = app(EscanerIngreso::class);
        $texto = $servicio->leerTexto(new UploadedFile('/tmp/resol-lectura.pdf', 'formato.pdf', 'application/pdf', null, true));
        $resumen = $servicio->resumenLectura();
        $this->assertGreaterThan(1000, mb_strlen($texto));
        $this->assertGreaterThan(0, $resumen['paginas_reforzadas']);
        $this->assertStringContainsString('CAS', $texto);
        fwrite(STDOUT, json_encode($resumen).PHP_EOL);
        Http::assertNothingSent();
    }

    public function test_real_local_model_handles_the_user_pdf_without_inventing_template_data(): void
    {
        if (getenv('OLLAMA_PDF_FORMATO') !== '1') {
            $this->markTestSkipped('Prueba local opcional del PDF proporcionado; no se incorpora al repositorio.');
        }
        Http::allowStrayRequests();
        $pdf = new UploadedFile('/tmp/resol-lectura.pdf', 'formato.pdf', 'application/pdf', null, true);
        $resultado = app(EscanerIngreso::class)->escanear($pdf);
        $this->assertSame([], $resultado['campos'], json_encode($resultado));
        $this->assertCount(3, $resultado['observaciones']);
    }

    public function test_real_local_model_reads_a_synthetic_pdf(): void
    {
        if (getenv('OLLAMA_PRUEBA_LOCAL') !== '1') {
            $this->markTestSkipped('Prueba manual del modelo local, sin datos personales.');
        }
        Http::allowStrayRequests();
        $bruta = '';
        Http::globalResponseMiddleware(function ($respuesta) use (&$bruta) {
            $bruta = (string) $respuesta->getBody();

            return $respuesta;
        });
        $this->travelTo(now()->setDate(2026, 10, 27));
        $stream = 'BT /F1 12 Tf 50 750 Td ';
        foreach (explode("\n", self::TEXTO) as $linea) {
            $stream .= '('.$linea.') Tj 0 -20 Td ';
        }
        $stream .= 'ET';
        $objetos = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream"];
        $contenido = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objetos as $i => $objeto) {
            $offsets[] = strlen($contenido);
            $contenido .= ($i + 1)." 0 obj\n".$objeto."\nendobj\n";
        }
        $xref = strlen($contenido);
        $contenido .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $contenido .= sprintf("%010d 00000 n \n", $offset);
        }
        $contenido .= "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
        $pdf = UploadedFile::fake()->createWithContent('contrato-ficticio.pdf', $contenido);
        $resultado = app(EscanerIngreso::class)->escanear($pdf);
        $valores = array_column($resultado['campos'], 'valor', 'clave');
        $this->assertStringContainsString('015-2026-UNIQ', $valores['numero_documento_vinculo'] ?? '');
        $this->assertStringContainsString('CAS', $valores['proceso_seleccion'] ?? '');
        $this->assertSame('2026-10-20', $valores['fecha_resultado_seleccion'] ?? null, $bruta);
    }
}
