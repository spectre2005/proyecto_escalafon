<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Support\Documentos\EscanerSecciones;
use Database\Seeders\Seccion06CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class EscanerSeccionesTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    protected function setUp(): void
    {
        parent::setUp();
        config(['escaner.activo' => true]);
    }

    public function test_apagado_no_se_ofrece_ni_lee_el_documento(): void
    {
        $this->actuarComo();
        $this->seed(Seccion06CatalogoSeeder::class);
        $legajo = Legajo::factory()->create();
        $tipo = TipoDocumento::query()->where('codigo', 'COMP_RECONOCIMIENTO_TIEMPO')->firstOrFail();
        $this->getJson('/api/v1/funcionalidades')->assertOk()->assertJsonPath('data.lector_ia', true);
        config(['escaner.activo' => false]);
        $this->getJson('/api/v1/funcionalidades')->assertOk()->assertJsonPath('data.lector_ia', false);
        $this->mock(EscanerSecciones::class, fn ($mock) => $mock->shouldNotReceive('leer'));
        $this->post('/api/v1/documentos/escanear', [
            'usuario_id' => $legajo->usuario_id, 'tipo_documento_id' => $tipo->id, 'campos' => ['emisor'], 'pdf' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertStatus(409);
    }

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

    public function test_el_esquema_exige_cada_campo_y_el_documento_va_como_texto_normal(): void
    {
        Http::fake(['ollama:11434/*' => Http::response(['message' => ['content' => json_encode(['emisor' => ['evidencia' => null, 'valor' => null]])]])]);
        $motor = new EscanerSecciones;
        $this->assertSame([], $motor->interpretar("UNIVERSIDAD DE EJEMPLO\nConstancia", 'Constancia', ['emisor']));
        $this->assertSame([], $motor->descartados);
        Http::assertSent(function ($request): bool {
            $campo = $request['format']['properties']['emisor'];
            $usuario = $request['messages'][1]['content'];

            return $campo['type'] === 'object' && $campo['required'] === ['evidencia', 'valor'] && $campo['properties']['valor']['type'] === ['string', 'null']
                && str_contains($usuario, "TEXTO DEL DOCUMENTO:\nUNIVERSIDAD DE EJEMPLO\nConstancia") && str_contains($usuario, '- emisor: entidad u órgano que emite el documento');
        });
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function fechasAceptadas(): array
    {
        return [
            'mes abreviado' => ['2024-08-12', '12 AGO 2024'],
            'abreviado con punto' => ['2024-08-12', '12 ago. 2024'],
            'año de dos cifras' => ['2024-09-01', '01/09/24'],
            'error de OCR en los dígitos' => ['2024-08-12', 'l2 de agosto de 2O24'],
            'error de OCR en el mes' => ['2024-09-01', '1 de setlembre de 2024'],
            'septiembre' => ['2024-09-01', '1 de septiembre de 2024'],
            'del año' => ['2024-08-12', '12 de agosto del año 2024'],
            'mes primero' => ['2024-08-12', 'Agosto 12, 2024'],
            'año con coma' => ['2024-08-12', '12 de agosto de 2,024'],
            'con guiones y mes' => ['2024-08-12', '12-AGO-2024'],
            'año primero' => ['2024-08-12', '2024/08/12'],
            'con espacios' => ['2024-08-12', '12 / 08 / 2024'],
            'días del mes' => ['2024-08-12', 'a los 12 días del mes de agosto de 2024'],
            'en letras' => ['2024-08-12', 'a los doce días del mes de agosto del año dos mil veinticuatro'],
            'en letras y compuesto' => ['2021-05-31', 'treinta y un días del mes de mayo de dos mil veintiuno'],
            'con la otra fecha al lado' => ['2025-12-31', 'vigencia del 01/09/24 al 31/12/25'],
        ];
    }

    #[DataProvider('fechasAceptadas')]
    public function test_acepta_fechas_con_formatos_reales_y_errores_de_ocr(string $valor, string $evidencia): void
    {
        $datos = (new EscanerSecciones)->validar(['fecha_inicio' => ['valor' => $valor, 'evidencia' => $evidencia]], "Documento: {$evidencia}.", ['fecha_inicio']);
        $this->assertSame($valor, $datos['fecha_inicio']['valor'] ?? null);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function fechasRechazadas(): array
    {
        return [
            'otro día' => ['2024-08-02', 'l2 de agosto de 2O24'],
            'otro mes' => ['2024-07-01', '1 de setlembre de 2024'],
            'otro año corto' => ['2023-09-01', '01/09/24'],
            'mes ambiguo' => ['2024-06-01', '1 de jurio de 2024'],
            'otro día en letras' => ['2024-08-13', 'a los doce días del mes de agosto del año dos mil veinticuatro'],
            'otro año en letras' => ['2023-08-12', 'a los doce días del mes de agosto del año dos mil veinticuatro'],
            'día y mes invertidos' => ['2024-12-08', '12/08/2024'],
        ];
    }

    #[DataProvider('fechasRechazadas')]
    public function test_sigue_rechazando_fechas_que_no_coinciden_con_la_cita(string $valor, string $evidencia): void
    {
        $motor = new EscanerSecciones;
        $this->assertSame([], $motor->validar(['fecha_inicio' => ['valor' => $valor, 'evidencia' => $evidencia]], "Documento: {$evidencia}.", ['fecha_inicio']));
        $this->assertSame(['fecha_inicio' => 'fecha_no_coincide'], $motor->descartados);
    }

    public function test_lo_que_no_se_comprueba_se_sugiere_sin_rellenar_y_lo_inventado_no(): void
    {
        $texto = 'Dado el 12.VIII.2024. Oficina de Personal. Se expide a solicitud de la interesada.';
        $motor = new EscanerSecciones;
        $datos = $motor->validar([
            'fecha_emision' => ['valor' => '2024-08-12', 'evidencia' => '12.VIII.2024'],
            'fecha_inicio' => ['valor' => '2023-04-15', 'evidencia' => 'Se expide a solicitud de la interesada'],
            'fecha_fin' => ['valor' => '2023-04-15', 'evidencia' => 'Esta cita no existe, 2023'],
            'emisor' => ['valor' => 'Oficina Central', 'evidencia' => 'Oficina de Personal'],
            'num_resolucion' => ['valor' => 'sin numero', 'evidencia' => 'sin numero'],
        ], $texto, ['fecha_emision', 'fecha_inicio', 'fecha_fin', 'emisor', 'num_resolucion']);
        $this->assertSame([], $datos);
        $this->assertSame(['fecha_emision', 'emisor'], array_keys($motor->sugerencias));
        $this->assertSame('2024-08-12', $motor->sugerencias['fecha_emision']['valor']);
        $this->assertSame('fecha_no_coincide', $motor->descartados['fecha_inicio']);
    }

    public function test_la_sugerencia_de_fecha_sale_de_la_cita_y_no_de_lo_que_leyo_mal_el_modelo(): void
    {
        $texto = 'Dado el l2 de agosto de 2O24. Rige del 01/09/24 al 31/12/25. Con fecha 1 de enero de 2099.';
        $motor = new EscanerSecciones;
        $motor->validar([
            'fecha_emision' => ['valor' => '2024-08-02', 'evidencia' => 'l2 de agosto de 2O24'],
            'fecha_inicio' => ['valor' => '2024-09-02', 'evidencia' => 'del 01/09/24 al 31/12/25'],
        ], $texto, ['fecha_emision', 'fecha_inicio']);
        $this->assertSame('2024-08-12', $motor->sugerencias['fecha_emision']['valor']);
        $this->assertSame('2024-09-02', $motor->sugerencias['fecha_inicio']['valor'], 'Con dos fechas en la cita no se elige una por el modelo.');

        $futura = new EscanerSecciones;
        $futura->validar(['fecha_emision' => ['valor' => '2024-08-02', 'evidencia' => '1 de enero de 2099']], $texto, ['fecha_emision']);
        $this->assertSame([], $futura->sugerencias);
    }

    public function test_un_numero_que_no_esta_en_su_cita_no_se_sugiere(): void
    {
        $motor = new EscanerSecciones;
        $motor->validar(['num_resolucion' => ['valor' => '12345', 'evidencia' => 'Se expide a solicitud de la interesada']], 'Se expide a solicitud de la interesada.', ['num_resolucion']);
        $this->assertSame('valor_fuera_de_cita', $motor->descartados['num_resolucion']);
        $this->assertSame([], $motor->sugerencias);
    }

    /** @return array<string, array{0: string, 1: ?array<string, string>, 2: ?string, 3: ?string, 4: ?string}> texto, emisor del modelo, rellenado, sugerido, motivo */
    public static function emisores(): array
    {
        $sinMembrete = "RESOLUCIÓN DE GERENCIA GENERAL N° 0412-2026-GG/MDQ\nCiudad Ejemplo, 15 de mayo de 2026\nOficina de Asesoría Jurídica opina. Ing. Luis Prueba firma.";
        $conMembrete = "UNIVERSIDAD DE PRUEBA\nRESOLUCIÓN DE GERENCIA GENERAL N° 0412-2026-GG/MDQ\nTexto.";
        $titulo = 'RESOLUCIÓN DE GERENCIA GENERAL N° 0412-2026-GG/MDQ';

        return [
            'toma el título' => [$sinMembrete, ['valor' => $titulo, 'evidencia' => $titulo], null, 'GERENCIA GENERAL - MDQ', 'es_el_titulo'],
            'toma al firmante' => [$sinMembrete, ['valor' => 'Ing. Luis Prueba', 'evidencia' => 'Ing. Luis Prueba'], null, 'GERENCIA GENERAL - MDQ', 'es_el_firmante'],
            'toma otra oficina' => [$sinMembrete, ['valor' => 'Oficina de Asesoría Jurídica', 'evidencia' => 'Oficina de Asesoría Jurídica'], null, 'GERENCIA GENERAL - MDQ', 'no_es_el_emisor'],
            'no devuelve nada' => [$sinMembrete, null, null, 'GERENCIA GENERAL - MDQ', null],
            'entidad y órgano' => [$conMembrete, ['valor' => 'UNIVERSIDAD DE PRUEBA', 'evidencia' => 'UNIVERSIDAD DE PRUEBA'], 'UNIVERSIDAD DE PRUEBA - GERENCIA GENERAL', null, null],
            'ya trae el órgano' => [$sinMembrete, ['valor' => 'Gerencia General', 'evidencia' => 'GERENCIA GENERAL'], 'Gerencia General', null, null],
            'adjetivo del título' => ["UNIVERSIDAD DE PRUEBA\nRESOLUCIÓN RECTORAL N° 0457-2024-UP\nTexto.", ['valor' => 'UNIVERSIDAD DE PRUEBA', 'evidencia' => 'UNIVERSIDAD DE PRUEBA'], 'UNIVERSIDAD DE PRUEBA - RECTORADO', null, null],
            'sin órgano en el título' => ["UNIVERSIDAD DE PRUEBA\nCONSTANCIA\nVISTO: la Resolución de Consejo N° 11-2020.", ['valor' => 'UNIVERSIDAD DE PRUEBA', 'evidencia' => 'UNIVERSIDAD DE PRUEBA'], 'UNIVERSIDAD DE PRUEBA', null, null],
        ];
    }

    #[DataProvider('emisores')]
    public function test_el_emisor_es_la_entidad_u_organo_y_nunca_el_titulo_ni_quien_firma(string $texto, ?array $modelo, ?string $rellenado, ?string $sugerido, ?string $motivo): void
    {
        Http::fake(['ollama:11434/*' => Http::response(['message' => ['content' => json_encode(['emisor' => $modelo ?? ['evidencia' => null, 'valor' => null]])]])]);
        $motor = new EscanerSecciones;
        $datos = $motor->interpretar($texto, 'Resolución', ['emisor']);
        $this->assertSame($rellenado, $datos['emisor']['valor'] ?? null);
        $this->assertSame($sugerido, $motor->sugerencias['emisor']['valor'] ?? null);
        $this->assertSame($motivo, $motor->descartados['emisor'] ?? null);
    }

    public function test_una_cita_no_puede_cortar_un_numero(): void
    {
        $texto = 'Dado el l2 de agosto de 2O24 con 120 horas.';
        $motor = new EscanerSecciones;
        $this->assertSame([], $motor->validar([
            'fecha_emision' => ['valor' => '2024-08-02', 'evidencia' => '2 de agosto de 2O24'],
            'horas' => ['valor' => '20', 'evidencia' => '20 horas'],
        ], $texto, ['fecha_emision', 'horas']));
        $this->assertSame('cita_no_encontrada', $motor->descartados['fecha_emision']);
        $this->assertSame('cita_no_encontrada', $motor->descartados['horas']);
    }

    public function test_la_cita_se_encuentra_aunque_el_ocr_altere_espacios_y_signos(): void
    {
        $datos = (new EscanerSecciones)->validar(['emisor' => ['valor' => 'Oficina de Personal', 'evidencia' => 'Oficina  de: Personal']], 'Emite la Oficina de Personal, Sede 1.', ['emisor']);
        $this->assertSame('Oficina de Personal', $datos['emisor']['valor'] ?? null);
    }

    public function test_la_cita_tolera_tildes_y_signos_que_confunde_el_ocr(): void
    {
        $texto = 'RESOLUCION RECTORAL N? 001-2026';
        $datos = (new EscanerSecciones)->validar(['nombre' => ['valor' => 'RESOLUCIÓN RECTORAL N° 001-2026', 'evidencia' => 'RESOLUCIÓN RECTORAL N° 001-2026']], $texto, ['nombre']);
        $this->assertSame('RESOLUCIÓN RECTORAL N° 001-2026', $datos['nombre']['valor'] ?? null);
        $this->assertSame('001-2026', (new EscanerSecciones)->extraerExplicitos($texto, ['num_resolucion'])['num_resolucion']['valor'] ?? null);
    }

    public function test_las_cantidades_se_quedan_con_el_numero(): void
    {
        $texto = 'Curso con una duración de 120 horas académicas.';
        $motor = new EscanerSecciones;
        $this->assertSame('120', $motor->validar(['horas' => ['valor' => '120 horas académicas', 'evidencia' => 'duración de 120 horas académicas']], $texto, ['horas'])['horas']['valor'] ?? null);
        $this->assertSame('120', $motor->validar(['horas' => ['valor' => '}120', 'evidencia' => '120 horas']], $texto, ['horas'])['horas']['valor'] ?? null);
        $this->assertSame([], $motor->validar(['horas' => ['valor' => '12 o 120', 'evidencia' => '120 horas']], $texto, ['horas']));
    }

    public function test_una_cita_literal_no_basta_si_el_dato_no_es_de_ese_tipo(): void
    {
        $texto = 'Se expide a doña ANA LUCÍA PRUEBA EJEMPLO por la Oficina a solicitud de la interesada.';
        $motor = new EscanerSecciones;
        $this->assertSame([], $motor->validar([
            'cargo' => ['valor' => 'doña ANA LUCÍA PRUEBA EJEMPLO', 'evidencia' => 'doña ANA LUCÍA PRUEBA EJEMPLO'],
            'emisor' => ['valor' => 'ANA LUCÍA PRUEBA', 'evidencia' => 'ANA LUCÍA PRUEBA'],
            'num_resolucion' => ['valor' => 'a solicitud de la interesada', 'evidencia' => 'a solicitud de la interesada'],
            'nombre' => ['valor' => 'Otro título', 'evidencia' => 'Otro título'],
        ], $texto, ['cargo', 'emisor', 'num_resolucion', 'nombre'], 'Ana Lucía Prueba Ejemplo'));
        $this->assertSame(['nombre' => 'cita_no_encontrada', 'cargo' => 'es_el_titular', 'emisor' => 'es_el_titular', 'num_resolucion' => 'numero_sin_digitos'], array_replace(['nombre' => null, 'cargo' => null, 'emisor' => null, 'num_resolucion' => null], $motor->descartados));
    }

    public function test_lee_una_pagina_girada_con_el_segundo_intento(): void
    {
        if (! is_executable('/usr/bin/tesseract') || ! is_executable('/usr/bin/convert')) {
            $this->markTestSkipped('Requiere las herramientas OCR de la imagen Docker.');
        }
        $imagen = imagecreatetruecolor(700, 300);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 255, 255, 255));
        $lineas = ['UNIVERSIDAD DE EJEMPLO PARA PRUEBAS', 'RESOLUCION RECTORAL N. 001-2026', 'Se designa en el cargo de docente auxiliar', 'a tiempo completo del departamento academico', 'desde el primero de enero de dos mil veintiseis', 'Registrese comuniquese y archivese'];
        foreach ($lineas as $indice => $linea) {
            imagestring($imagen, 5, 20, 20 + $indice * 40, $linea, imagecolorallocate($imagen, 0, 0, 0));
        }
        // Tamaño justo para el OCR: la suite completa corre con 128 MB.
        $grande = imagescale($imagen, 1400);
        $girada = imagerotate($grande, 90, 0);
        ob_start();
        imagejpeg($girada, null, 85);
        $jpg = ob_get_clean();
        [$ancho, $alto] = [imagesx($girada), imagesy($girada)];
        unset($imagen, $grande, $girada);
        $stream = "q {$ancho} 0 0 {$alto} 0 0 cm /Im1 Do Q";
        $objetos = ['<</Type /Catalog /Pages 2 0 R>>', '<</Type /Pages /Kids [3 0 R] /Count 1>>', "<</Type /Page /Parent 2 0 R /MediaBox [0 0 {$ancho} {$alto}] /Resources <</XObject <</Im1 4 0 R>>>> /Contents 5 0 R>>", "<</Type /XObject /Subtype /Image /Width {$ancho} /Height {$alto} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpg).">>\nstream\n".$jpg."\nendstream", '<</Length '.strlen($stream).">>\nstream\n".$stream."\nendstream"];
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
        $motor = new EscanerSecciones;
        $texto = $motor->leer(UploadedFile::fake()->createWithContent('girada.pdf', $contenido));
        $this->assertStringContainsString('001-2026', $texto);
        $this->assertSame('ocr_reforzado', $motor->paginas[0]['metodo']);
    }

    public function test_la_auditoria_registra_la_lectura_sin_el_contenido_del_documento(): void
    {
        $this->actuarComo();
        $this->seed(Seccion06CatalogoSeeder::class);
        $legajo = Legajo::factory()->create();
        $tipo = TipoDocumento::query()->where('codigo', 'COMP_RECONOCIMIENTO_TIEMPO')->firstOrFail();
        $this->mock(EscanerSecciones::class, function ($mock): void {
            $mock->shouldReceive('leer')->once()->andReturn('UNIVERSIDAD DE EJEMPLO');
            $mock->shouldReceive('interpretar')->once()->andReturnUsing(function () use ($mock): array {
                $mock->descartados = ['fecha_emision' => 'fecha_no_coincide'];
                $mock->sugerencias = ['fecha_emision' => ['valor' => '2024-08-12', 'evidencia' => '12.VIII.2024']];

                return ['emisor' => ['valor' => 'UNIVERSIDAD DE EJEMPLO', 'evidencia' => 'UNIVERSIDAD DE EJEMPLO']];
            });
        });
        $this->post('/api/v1/documentos/escanear', [
            'usuario_id' => $legajo->usuario_id, 'tipo_documento_id' => $tipo->id, 'campos' => ['emisor', 'fecha_emision'], 'pdf' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.descartados.fecha_emision', 'fecha_no_coincide')
            ->assertJsonPath('data.sugerencias.fecha_emision.valor', '2024-08-12');
        $evento = AuditoriaEvento::query()->where('accion', 'documento.lectura_ia')->firstOrFail();
        $this->assertSame($legajo->usuario_id, $evento->usuario_titular_id);
        $this->assertStringContainsString('fecha_no_coincide', (string) $evento->datos_despues);
        $this->assertStringContainsString('"campos_propuestos":["emisor"]', (string) $evento->datos_despues);
        $this->assertStringNotContainsString('UNIVERSIDAD DE EJEMPLO', (string) $evento->datos_despues);
        $this->assertStringContainsString('"campos_sugeridos":["fecha_emision"]', (string) $evento->datos_despues);
        $this->assertStringNotContainsString('12.VIII.2024', (string) $evento->datos_despues);
        $this->assertStringNotContainsString('2024-08-12', (string) $evento->datos_despues);
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
