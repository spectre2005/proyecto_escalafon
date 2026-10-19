<?php

namespace Tests\Concerns;

trait PdfValido
{
    protected static function pdfValido(int $paginas = 1, int $relleno = 0, bool $comprimido = false, int $rotacion = 0, string $texto = 'q Q'): string
    {
        $hijos = [];
        $objetos = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
        $streamId = $paginas + 3;
        for ($pagina = 0; $pagina < $paginas; $pagina++) {
            $id = $pagina + 3;
            $hijos[] = $id.' 0 R';
            $objetos[$id] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Rotate {$rotacion} /Contents {$streamId} 0 R >>";
        }
        $objetos[2] = '<< /Type /Pages /Count '.$paginas.' /Kids ['.implode(' ', $hijos).'] >>';
        $contenido = $comprimido ? gzcompress($texto) : $texto;
        $objetos[$streamId] = '<< /Length '.strlen($contenido).($comprimido ? ' /Filter /FlateDecode' : '').">>\nstream\n".$contenido."\nendstream";
        ksort($objetos);
        $pdf = "%PDF-1.5\n".($relleno > 0 ? '%'.str_repeat('x', $relleno)."\n" : '');
        $offsets = [];
        foreach ($objetos as $id => $objeto) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$objeto."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer'."\n<< /Size ".(count($objetos) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";

        return $pdf;
    }
}
