<?php

namespace Tests\Unit\Support\Documentos;

use App\Support\Documentos\ArchivoPdf;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ArchivoPdfTest extends TestCase
{
    /** @var list<string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
        parent::tearDown();
    }

    private function archivo(string $contenido, string $nombre = 'documento.pdf'): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($ruta, $contenido);
        $this->temporales[] = $ruta;

        return new UploadedFile($ruta, $nombre, 'application/pdf', null, true);
    }

    private function pdf(string $cuerpo): string
    {
        return "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n{$cuerpo}\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    public function test_reconoce_un_pdf_por_su_contenido(): void
    {
        $this->assertTrue(ArchivoPdf::esPdf($this->archivo($this->pdf('2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj'))));
    }

    public function test_rechaza_un_texto_con_extension_pdf(): void
    {
        $this->assertFalse(ArchivoPdf::esPdf($this->archivo('Esto no es un PDF, solo texto.')));
    }

    public function test_rechaza_un_texto_que_menciona_la_firma_pdf_en_medio(): void
    {
        $this->assertFalse(ArchivoPdf::esPdf($this->archivo("Hola, este texto contiene %PDF- pero no es un PDF.\n")));
    }

    public function test_rechaza_una_imagen_png_renombrada(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $this->assertFalse(ArchivoPdf::esPdf($this->archivo($png, 'foto.pdf')));
    }

    public function test_el_hash_es_sha256_del_contenido(): void
    {
        $contenido = $this->pdf('2 0 obj << /Type /Pages /Count 1 >> endobj');
        $hash = ArchivoPdf::hash($this->archivo($contenido));

        $this->assertSame(hash('sha256', $contenido), $hash);
        $this->assertSame(64, strlen($hash));
    }

    public function test_dos_archivos_distintos_tienen_hash_distinto(): void
    {
        $this->assertNotSame(
            ArchivoPdf::hash($this->archivo($this->pdf('2 0 obj << /Type /Pages /Count 1 >> endobj'))),
            ArchivoPdf::hash($this->archivo($this->pdf('2 0 obj << /Type /Pages /Count 2 >> endobj'))),
        );
    }

    public function test_cuenta_paginas_con_type_antes_de_count(): void
    {
        $this->assertSame(3, ArchivoPdf::paginas($this->archivo($this->pdf('2 0 obj << /Type /Pages /Kids [3 0 R 4 0 R 5 0 R] /Count 3 >> endobj'))));
    }

    public function test_cuenta_paginas_con_count_antes_de_type(): void
    {
        $this->assertSame(5, ArchivoPdf::paginas($this->archivo($this->pdf('2 0 obj << /Count 5 /Kids [] /Type /Pages >> endobj'))));
    }

    public function test_con_varios_nodos_pages_toma_el_mayor_que_es_la_raiz(): void
    {
        $cuerpo = "2 0 obj << /Type /Pages /Kids [6 0 R 7 0 R] /Count 7 >> endobj\n"
            ."6 0 obj << /Type /Pages /Parent 2 0 R /Kids [] /Count 2 >> endobj\n"
            .'7 0 obj << /Type /Pages /Parent 2 0 R /Kids [] /Count 5 >> endobj';

        $this->assertSame(7, ArchivoPdf::paginas($this->archivo($this->pdf($cuerpo))));
    }

    public function test_no_confunde_el_count_de_los_marcadores_con_las_paginas(): void
    {
        $cuerpo = "2 0 obj << /Type /Pages /Kids [] /Count 2 >> endobj\n"
            .'8 0 obj << /Type /Outlines /Count 40 >> endobj';

        $this->assertSame(2, ArchivoPdf::paginas($this->archivo($this->pdf($cuerpo))));
    }

    public function test_lee_el_conteo_dentro_de_un_object_stream_comprimido(): void
    {
        $comprimido = gzcompress('<< /Type /Pages /Kids [] /Count 4 >>');
        $cuerpo = '9 0 obj << /Type /ObjStm /Filter /FlateDecode /Length '.strlen($comprimido)." >>\nstream\n{$comprimido}\nendstream\nendobj";

        $this->assertSame(4, ArchivoPdf::paginas($this->archivo($this->pdf($cuerpo))));
    }

    public function test_sin_arbol_de_paginas_devuelve_null(): void
    {
        $this->assertNull(ArchivoPdf::paginas($this->archivo($this->pdf('2 0 obj << /Type /Font >> endobj'))));
    }

    public function test_cuenta_paginas_cuando_el_nodo_pages_tiene_un_diccionario_anidado(): void
    {
        // Un nodo /Pages con /Resources << ... >> en línea es PDF válido (recursos heredados).
        $cuerpo = '2 0 obj << /Type /Pages /Resources << /Font << /F1 10 0 R >> >> /Kids [] /Count 6 >> endobj';
        $this->assertSame(6, ArchivoPdf::paginas($this->archivo($this->pdf($cuerpo))));
    }

    public function test_acepta_bytes_previos_a_la_cabecera_si_empieza_en_una_linea_propia(): void
    {
        $this->assertTrue(ArchivoPdf::tieneCabecera("basura previa\n%PDF-1.7\n"));
        $this->assertTrue(ArchivoPdf::tieneCabecera('%PDF-1.4'));
    }

    public function test_la_cabecera_exige_version_y_estar_en_los_primeros_1024_bytes(): void
    {
        $this->assertFalse(ArchivoPdf::tieneCabecera('%PDF-'));
        $this->assertFalse(ArchivoPdf::tieneCabecera('texto %PDF-1.4'));
        $this->assertFalse(ArchivoPdf::tieneCabecera(str_repeat('x', 1100)."\n%PDF-1.4"));
    }

    public function test_un_count_dentro_de_un_diccionario_anidado_no_reemplaza_al_del_nodo(): void
    {
        $cuerpo = '2 0 obj << /Type /Pages /Resources << /Count 99 >> /Kids [] /Count 3 >> endobj';

        $this->assertSame(3, ArchivoPdf::paginas($this->archivo($this->pdf($cuerpo))));
    }
}
