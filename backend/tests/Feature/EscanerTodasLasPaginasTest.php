<?php

namespace Tests\Feature;

use App\Models\ParametroSistema;
use App\Support\Documentos\EscanerSecciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** El lector lee todas las páginas (hasta el máximo) y al modelo solo le manda los fragmentos relevantes. */
class EscanerTodasLasPaginasTest extends TestCase
{
    use RefreshDatabase;

    /** PDF de texto con una página por cada lista de líneas. */
    private function pdf(array $paginas): UploadedFile
    {
        $objetos = ['<</Type /Catalog /Pages 2 0 R>>', ''];
        $hijos = [];
        foreach ($paginas as $lineas) {
            $stream = 'BT /F1 11 Tf 50 750 Td ';
            foreach ($lineas as $linea) {
                $stream .= '('.str_replace(['(', ')'], ['\\(', '\\)'], $linea).') Tj 0 -16 Td ';
            }
            $stream .= 'ET';
            $pagina = count($objetos) + 1;
            $hijos[] = "{$pagina} 0 R";
            $objetos[] = '<</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources <</Font <</F1 '.($pagina + 1).' 0 R>>>> /Contents '.($pagina + 2).' 0 R>>';
            $objetos[] = '<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>';
            $objetos[] = '<</Length '.strlen($stream).">>\nstream\n{$stream}\nendstream";
        }
        $objetos[1] = '<</Type /Pages /Kids ['.implode(' ', $hijos).'] /Count '.count($paginas).'>>';
        $contenido = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objetos as $indice => $objeto) {
            $offsets[] = strlen($contenido);
            $contenido .= ($indice + 1)." 0 obj\n{$objeto}\nendobj\n";
        }
        $xref = strlen($contenido);
        $contenido .= "xref\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n".implode('', array_map(fn (int $o): string => sprintf("%010d 00000 n \n", $o), $offsets));
        $contenido .= 'trailer <</Size '.(count($objetos) + 1)." /Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";

        return UploadedFile::fake()->createWithContent('varias.pdf', $contenido);
    }

    /** Página con suficiente texto digital para no pasar por OCR. */
    private function pagina(int $numero, string $dato): array
    {
        return ["Pagina {$numero} del expediente administrativo de la universidad", $dato, 'Texto de relleno que no aporta datos al acto principal.', 'Otro parrafo de relleno sin numeros ni fechas.'];
    }

    public function test_lee_todas_las_paginas_e_informa_el_avance(): void
    {
        $escaner = new EscanerSecciones;
        $avance = [];

        $texto = $escaner->leer($this->pdf(array_map(fn (int $n): array => $this->pagina($n, "Dato de la pagina {$n}"), range(1, 5))),
            function (int $pagina, int $total) use (&$avance): void {
                $avance[] = "{$pagina}/{$total}";
            });

        $this->assertSame(['1/5', '2/5', '3/5', '4/5', '5/5'], $avance);
        $this->assertSame(5, $escaner->paginasDocumento);
        $this->assertStringContainsString('Dato de la pagina 5', $texto);
        $this->assertCount(5, $escaner->paginas);
    }

    public function test_respeta_el_maximo_de_paginas_configurado(): void
    {
        ParametroSistema::factory()->create(['clave' => 'escaner_paginas_maximas', 'valor' => '2']);
        $escaner = new EscanerSecciones;

        $texto = $escaner->leer($this->pdf(array_map(fn (int $n): array => $this->pagina($n, "Dato de la pagina {$n}"), range(1, 4))));

        $this->assertSame(4, $escaner->paginasDocumento);
        $this->assertCount(2, $escaner->paginas);
        $this->assertStringContainsString('Dato de la pagina 2', $texto);
        $this->assertStringNotContainsString('Dato de la pagina 3', $texto);
    }

    public function test_un_documento_corto_va_entero_al_modelo(): void
    {
        $texto = "\n[Página 1]\nRESOLUCIÓN N° 001-2026\n[Página 2]\nFirmado el 2 de enero de 2026";

        $this->assertSame($texto, (new EscanerSecciones)->paraModelo($texto));
    }

    public function test_un_documento_largo_manda_primera_ultima_y_lineas_con_datos(): void
    {
        $relleno = fn (int $n): string => implode("\n", array_fill(0, 40, "linea de relleno sin interes numero{$n}"));
        $texto = "\n[Página 1]\nRESOLUCIÓN RECTORAL N° 0456-2026-R\n".$relleno(1)
            ."\n[Página 2]\n".$relleno(2)."\nSE RESUELVE: designar al servidor\nen el cargo de jefe de oficina\n".$relleno(2)
            ."\n[Página 3]\n".$relleno(3)
            ."\n[Página 4]\n".$relleno(4)."\nLima, 15 de marzo de 2026. Firma del rector.";

        $modelo = (new EscanerSecciones)->paraModelo($texto);

        $this->assertLessThanOrEqual(6000, mb_strlen($modelo));
        $this->assertStringContainsString('RESOLUCIÓN RECTORAL N° 0456-2026-R', $modelo);
        $this->assertStringContainsString("SE RESUELVE: designar al servidor\nen el cargo de jefe de oficina", $modelo, 'la línea siguiente acompaña a la clave');
        $this->assertStringContainsString('15 de marzo de 2026', $modelo);
        $this->assertStringNotContainsString('[Página 3]', $modelo, 'una página sin datos no se manda');
        $this->assertStringNotContainsString('numero2', $modelo, 'de las intermedias solo van las líneas con datos');
    }

    public function test_una_pagina_en_blanco_no_desordena_las_paginas(): void
    {
        $largo = str_repeat("relleno\n", 900);
        $texto = "\n[Página 1]\nENCABEZADO\n{$largo}\n[Página 2]\n\n[Página 3]\nRESOLUCIÓN N° 9-2026\n{$largo}\n[Página 4]\nCIERRE";

        $modelo = (new EscanerSecciones)->paraModelo($texto);

        $this->assertStringContainsString("[Página 3]\nRESOLUCIÓN N° 9-2026", $modelo);
        $this->assertStringContainsString("[Página 4]\nCIERRE", $modelo);
        $this->assertStringStartsWith("[Página 1]\nENCABEZADO", $modelo);
    }
}
