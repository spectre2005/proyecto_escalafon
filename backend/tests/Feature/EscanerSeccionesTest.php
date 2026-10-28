<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Support\Documentos\EscanerSecciones;
use Database\Seeders\Seccion06CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class EscanerSeccionesTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    public function test_lectura_real_de_un_pdf_de_compensaciones_con_ollama_local(): void
    {
        if (getenv('ESCANER_PRUEBA_REAL') !== '1') {
            $this->markTestSkipped('Prueba local opcional: requiere Ollama y Poppler.');
        }
        $lineas = ['UNIVERSIDAD DE EJEMPLO', 'RESOLUCION N. 001-2026', 'Fecha de emision: 20 de octubre de 2026', 'Se reconoce expresamente un tiempo de servicios de 5 anios, 2 meses y 3 dias.'];
        $stream = 'BT /F1 12 Tf 50 750 Td ';
        foreach ($lineas as $linea) {
            $stream .= '('.$linea.') Tj 0 -24 Td ';
        }
        $stream .= 'ET';
        $objetos = ['<</Type /Catalog /Pages 2 0 R>>', '<</Type /Pages /Kids [3 0 R] /Count 1>>', '<</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources <</Font <</F1 4 0 R>>>> /Contents 5 0 R>>', '<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>', '<</Length '.strlen($stream).">>\nstream\n".$stream."\nendstream"];
        $contenido = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objetos as $indice => $objeto) {
            $offsets[] = strlen($contenido);
            $contenido .= ($indice + 1)." 0 obj\n".$objeto."\nendobj\n";
        }
        $xref = strlen($contenido);
        $contenido .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $contenido .= sprintf("%010d 00000 n \n", $offset);
        }
        $contenido .= "trailer <</Size 6 /Root 1 0 R>>\nstartxref\n".$xref."\n%%EOF";
        $pdf = UploadedFile::fake()->createWithContent('compensaciones.pdf', $contenido);
        $escaner = new EscanerSecciones;
        $texto = $escaner->leer($pdf);
        $this->assertStringContainsString('001-2026', $texto);
        $campos = $escaner->interpretar($texto, 'Resolución de reconocimiento de tiempo de servicios', ['emisor', 'num_resolucion', 'fecha_emision', 'anios', 'meses', 'dias']);
        $this->assertSame('5', $campos['anios']['valor'] ?? null);
        $this->assertSame('2', $campos['meses']['valor'] ?? null);
        $this->assertSame('3', $campos['dias']['valor'] ?? null);
        $this->assertSame('2026-10-20', $campos['fecha_emision']['valor'] ?? null);
    }

    public function test_lee_un_pdf_que_contiene_solo_una_imagen_con_ocr_local(): void
    {
        if (! is_executable('/usr/bin/tesseract') || ! is_executable('/usr/bin/pdftoppm')) {
            $this->markTestSkipped('Requiere las herramientas OCR de la imagen Docker.');
        }
        $imagen = imagecreatetruecolor(800, 180);
        $blanco = imagecolorallocate($imagen, 255, 255, 255);
        $negro = imagecolorallocate($imagen, 0, 0, 0);
        imagefill($imagen, 0, 0, $blanco);
        foreach (['UNIVERSIDAD DE EJEMPLO', 'RESOLUCION N. 001-2026', 'Fecha de emision: 20 de octubre de 2026', 'Se reconoce 5 anios, 2 meses y 3 dias.'] as $indice => $linea) {
            imagestring($imagen, 5, 20, 20 + $indice * 30, $linea, $negro);
        }
        ob_start();
        imagejpeg($imagen, null, 95);
        $jpg = ob_get_clean();
        $stream = 'q 800 0 0 180 0 0 cm /Im1 Do Q';
        $objetos = ['<</Type /Catalog /Pages 2 0 R>>', '<</Type /Pages /Kids [3 0 R] /Count 1>>', '<</Type /Page /Parent 2 0 R /MediaBox [0 0 800 180] /Resources <</XObject <</Im1 4 0 R>>>> /Contents 5 0 R>>', '<</Type /XObject /Subtype /Image /Width 800 /Height 180 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpg).">>\nstream\n".$jpg."\nendstream", '<</Length '.strlen($stream).">>\nstream\n".$stream."\nendstream"];
        $contenido = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objetos as $indice => $objeto) {
            $offsets[] = strlen($contenido);
            $contenido .= ($indice + 1)." 0 obj\n".$objeto."\nendobj\n";
        }
        $xref = strlen($contenido);
        $contenido .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $contenido .= sprintf("%010d 00000 n \n", $offset);
        }
        $contenido .= "trailer <</Size 6 /Root 1 0 R>>\nstartxref\n".$xref."\n%%EOF";
        $pdf = UploadedFile::fake()->createWithContent('imagen.pdf', $contenido);
        $texto = (new EscanerSecciones)->leer($pdf);
        $this->assertStringContainsString('UNIVERSIDAD DE EJEMPLO', $texto);
        $this->assertStringContainsString('001-2026', $texto);
    }

    public function test_extrae_con_evidencia_y_rechaza_valores_inventados_o_fechas_incorrectas(): void
    {
        $texto = 'UNIVERSIDAD DE EJEMPLO. Resolución N.º 001-2026. Emitida el 20 de octubre de 2026. Se reconoce 5 años, 2 meses y 3 días.';
        Http::fake(['ollama:11434/*' => Http::response(['message' => ['content' => json_encode([
            'emisor' => ['valor' => 'UNIVERSIDAD DE EJEMPLO', 'evidencia' => 'UNIVERSIDAD DE EJEMPLO'],
            'anios' => ['valor' => '5', 'evidencia' => '5 años'],
            'meses' => ['valor' => '8', 'evidencia' => '2 meses'],
            'dias' => ['valor' => '3', 'evidencia' => '3 días'],
            'fecha_emision' => ['valor' => '2026-10-21', 'evidencia' => '20 de octubre de 2026'],
            'nombre' => ['valor' => 'Documento inventado', 'evidencia' => 'Documento inventado'],
            'folio_inicio' => ['valor' => '99', 'evidencia' => '99'],
        ])]])]);
        $campos = (new EscanerSecciones)->interpretar($texto, 'Reconocimiento de tiempo', ['emisor', 'fecha_emision', 'nombre']);
        $cantidades = (new EscanerSecciones)->validar(['anios' => ['valor' => '5', 'evidencia' => '5 años'], 'meses' => ['valor' => '8', 'evidencia' => '2 meses'], 'dias' => ['valor' => '3', 'evidencia' => '3 días']], $texto, ['anios', 'meses', 'dias']);
        $campos += $cantidades;
        $this->assertSame(['emisor', 'anios', 'dias'], array_keys($campos));
        Http::assertSent(fn ($request): bool => $request['stream'] === false && $request['options']['temperature'] === 0 && $request['format']['additionalProperties'] === false);
        $validos = (new EscanerSecciones)->validar(['fecha_emision' => ['valor' => '2026-10-20', 'evidencia' => '20 de octubre de 2026']], $texto, ['fecha_emision']);
        $this->assertSame('2026-10-20', $validos['fecha_emision']['valor']);
    }

    public function test_datos_explicitos_no_confunden_fecha_con_tiempo_ni_resolucion_con_pago(): void
    {
        $motor = new EscanerSecciones;
        $texto = "RESOLUCION RECTORAL N. 001-2026-UNIQ\nFecha de emision: 20 de octubre de 2026\nSe reconoce 5 años, 2 meses y 3 días.";
        $campos = $motor->extraerExplicitos($texto, ['num_resolucion', 'numero_documento', 'fecha_emision', 'anios', 'meses', 'dias']);
        $this->assertSame('001-2026-UNIQ', $campos['num_resolucion']['valor']);
        $this->assertSame('2026-10-20', $campos['fecha_emision']['valor']);
        $this->assertSame('5', $campos['anios']['valor']);
        $this->assertSame('2', $campos['meses']['valor']);
        $this->assertSame('3', $campos['dias']['valor']);
        $this->assertArrayNotHasKey('numero_documento', $campos);
        $this->assertSame([], $motor->extraerExplicitos('Periodo del 5 de enero al 3 de febrero de 2026.', ['anios', 'meses', 'dias']));
    }

    public function test_escanear_no_guarda_documentos_ni_modifica_el_legajo(): void
    {
        $this->actuarComo();
        $this->seed(Seccion06CatalogoSeeder::class);
        $legajo = Legajo::factory()->create();
        $tipo = TipoDocumento::query()->where('codigo', 'COMP_RECONOCIMIENTO_TIEMPO')->firstOrFail();
        $this->mock(EscanerSecciones::class, function ($mock): void {
            $mock->shouldReceive('leer')->once()->andReturn('UNIVERSIDAD DE EJEMPLO');
            $mock->shouldReceive('interpretar')->once()->andReturn(['emisor' => ['valor' => 'UNIVERSIDAD DE EJEMPLO', 'evidencia' => 'UNIVERSIDAD DE EJEMPLO']]);
        });
        $antes = DocumentoUsuario::query()->count();
        $this->post('/api/v1/documentos/escanear', [
            'usuario_id' => $legajo->usuario_id, 'tipo_documento_id' => $tipo->id, 'campos' => ['emisor'], 'pdf' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.campos.emisor.valor', 'UNIVERSIDAD DE EJEMPLO');
        $this->assertDatabaseCount('documentos_usuario', $antes);
        $this->assertSame('activo', $legajo->fresh()->estado_legajo);
    }

    public function test_rechaza_campos_administrativos_y_usuarios_sin_permiso(): void
    {
        $this->actuarComo();
        $this->seed(Seccion06CatalogoSeeder::class);
        $legajo = Legajo::factory()->create();
        $tipo = TipoDocumento::query()->where('codigo', 'COMP_RECONOCIMIENTO_TIEMPO')->firstOrFail();
        $payload = ['usuario_id' => $legajo->usuario_id, 'tipo_documento_id' => $tipo->id, 'campos' => ['folio_inicio'], 'pdf' => $this->pdf()];
        $this->post('/api/v1/documentos/escanear', $payload, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('campos.0');
        $this->actuarComo(codigo: 'autoservicio_legajo');
        $this->post('/api/v1/documentos/escanear', $payload, ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_rechaza_tipos_sensibles_antes_de_llamar_al_motor(): void
    {
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->seed(Seccion06CatalogoSeeder::class);
        $legajo = Legajo::factory()->create();
        $tipo = TipoDocumento::query()->where('codigo', 'COMP_RECONOCIMIENTO_TIEMPO')->firstOrFail();
        $tipo->update(['es_dato_sensible' => true]);
        $this->mock(EscanerSecciones::class, fn ($mock) => $mock->shouldNotReceive('leer'));
        $this->post('/api/v1/documentos/escanear', [
            'usuario_id' => $legajo->usuario_id, 'tipo_documento_id' => $tipo->id, 'campos' => ['emisor'], 'pdf' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }
}
