<?php

namespace App\Support\Documentos;

/**
 * ¿El documento leído es de la persona del legajo? Compara el texto del PDF (lo que leyó el escáner) con el DNI y el
 * nombre del titular. Es una búsqueda de texto, sin modelo: no decide nada, solo avisa antes de guardar.
 *
 * Estados:
 * - coincide: aparece el DNI de la persona.
 * - coincide_nombre: no hay DNI en el texto, pero sí su nombre.
 * - dni_distinto: hay otro DNI, pero el nombre sí coincide (puede ser un error de lectura del escaneo).
 * - distinto: hay DNI y ninguno es el de la persona, y su nombre no aparece.
 * - sin_datos: no aparece ni su DNI ni su nombre en las páginas leídas.
 */
final class TitularDocumento
{
    /** DNI precedido de su rótulo: evita confundirlo con números de resolución, expedientes o montos. */
    private const PATRON_DNI = '/(?:\bD\s*\.?\s*N\s*\.?\s*I\b\.?|DOCUMENTO\s+NACIONAL\s+DE\s+IDENTIDAD|IDENTIFICAD[OA]\s+CON)[^\d\n]{0,25}?(\d{8})(?!\d)/u';

    /**
     * @param  array{dni: ?string, nombres: ?string, apellido_paterno: ?string, apellido_materno: ?string}  $persona
     * @return array{estado: string, mensaje: string, dni_leidos: list<string>}
     */
    public static function comparar(string $texto, array $persona): array
    {
        $normalizado = self::normalizar($texto);
        $dnis = self::dnis($normalizado);
        $dni = trim((string) ($persona['dni'] ?? ''));
        $nombre = self::nombreCoincide($normalizado, $persona);

        if ($dni !== '' && in_array($dni, $dnis, true)) {
            return self::resultado('coincide', 'El DNI del documento coincide con la persona de este legajo.', $dnis);
        }
        if ($dnis !== [] && $nombre) {
            return self::resultado('dni_distinto', 'El nombre coincide, pero el DNI leído ('.implode(', ', $dnis).') no es el de esta persona. '
                .'Puede ser un error de lectura del escaneo: compruébalo en el PDF.', $dnis);
        }
        if ($dnis !== []) {
            return self::resultado('distinto', 'Este documento parece ser de otra persona: el DNI leído ('.implode(', ', $dnis).') no es el de este legajo '
                .'y su nombre no aparece. Revisa que estés en el legajo correcto antes de guardar.', $dnis);
        }
        if ($nombre) {
            return self::resultado('coincide_nombre', 'El nombre del documento coincide con la persona de este legajo (no se encontró DNI).', []);
        }

        return self::resultado('sin_datos', 'No se encontró el DNI ni el nombre de la persona en las páginas leídas. Comprueba que el documento sea suyo.', []);
    }

    /** @return list<string> DNI rotulados que aparecen en el texto, sin repetir. */
    public static function dnis(string $textoNormalizado): array
    {
        preg_match_all(self::PATRON_DNI, $textoNormalizado, $coincidencias);

        return array_values(array_unique($coincidencias[1]));
    }

    /** El apellido paterno y, además, el materno o el primer nombre, como palabras completas. */
    private static function nombreCoincide(string $texto, array $persona): bool
    {
        $paterno = self::normalizar((string) ($persona['apellido_paterno'] ?? ''));
        if (mb_strlen($paterno) < 2 || ! self::contiene($texto, $paterno)) {
            return false;
        }
        $materno = self::normalizar((string) ($persona['apellido_materno'] ?? ''));
        $primerNombre = explode(' ', self::normalizar((string) ($persona['nombres'] ?? '')))[0];

        return ($materno !== '' && self::contiene($texto, $materno)) || (mb_strlen($primerNombre) >= 2 && self::contiene($texto, $primerNombre));
    }

    private static function contiene(string $texto, string $palabras): bool
    {
        return preg_match('/(?<![\p{L}])'.preg_quote($palabras, '/').'(?![\p{L}])/u', $texto) === 1;
    }

    /** Mayúsculas, sin tildes y con espacios simples: el OCR y los documentos escriben los nombres de muchas formas. */
    private static function normalizar(string $texto): string
    {
        $sinTildes = strtr(mb_strtoupper($texto, 'UTF-8'), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);

        return trim((string) preg_replace('/[ \t]+/u', ' ', $sinTildes));
    }

    /** @param  list<string>  $dnis */
    private static function resultado(string $estado, string $mensaje, array $dnis): array
    {
        return ['estado' => $estado, 'mensaje' => $mensaje, 'dni_leidos' => $dnis];
    }
}
