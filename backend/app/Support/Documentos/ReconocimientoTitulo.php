<?php

namespace App\Support\Documentos;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

/** Lectura local y sugerencias revisables; no verifica autenticidad ni completa datos ausentes. */
class ReconocimientoTitulo
{
    public const MAX_PAGINAS = 5;

    public function extraer(UploadedFile $archivo): array
    {
        $directorio = sys_get_temp_dir().'/titulo-'.Str::uuid();
        if (! mkdir($directorio, 0700)) {
            abort(503, 'No se pudo preparar la lectura del documento.');
        }
        $limite = microtime(true) + 40;
        try {
            $ruta = $archivo->getRealPath();
            $mime = $archivo->getMimeType();
            $esPdf = $mime === 'application/pdf' && ArchivoPdf::esPdf($archivo);
            if (! $esPdf && ! in_array($mime, ['image/jpeg', 'image/png'], true)) {
                throw ValidationException::withMessages(['archivo' => 'Selecciona un PDF, JPG o PNG válido.']);
            }
            $paginas = 1;
            if ($esPdf) {
                $info = $this->comando(['pdfinfo', $ruta], $limite);
                if (! preg_match('/^Pages:\s+(\d+)/m', $info, $m)) {
                    throw ValidationException::withMessages(['archivo' => 'No se pudo leer el PDF. Comprueba que no tenga contraseña y que no esté dañado.']);
                }
                $paginas = (int) $m[1];
                if ($paginas < 1 || $paginas > self::MAX_PAGINAS) {
                    throw ValidationException::withMessages(['archivo' => 'Selecciona el título con un máximo de cinco páginas.']);
                }
            } else {
                $tamano = @getimagesize($ruta);
                if (! $tamano || $tamano[0] * $tamano[1] > 20000000) {
                    throw ValidationException::withMessages(['archivo' => 'La imagen no es válida o supera los 20 megapíxeles.']);
                }
            }
            $textos = [];
            $metodos = [];
            for ($pagina = 1; $pagina <= $paginas; $pagina++) {
                $texto = $esPdf ? $this->comando(['pdftotext', '-f', (string) $pagina, '-l', (string) $pagina, '-layout', '-enc', 'UTF-8', $ruta, '-'], $limite) : '';
                $metodo = 'texto_pdf';
                if (mb_strlen(preg_replace('/\s+/u', '', $texto)) < 80) {
                    $imagen = $ruta;
                    if ($esPdf) {
                        $prefijo = $directorio.'/pagina-'.$pagina;
                        $this->comando(['pdftoppm', '-f', (string) $pagina, '-l', (string) $pagina, '-singlefile', '-scale-to', '2600', '-gray', '-png', $ruta, $prefijo], $limite);
                        $imagen = $prefijo.'.png';
                    }
                    $texto = $this->comando(['tesseract', $imagen, 'stdout', '-l', 'spa+eng', '--psm', '3', 'quiet'], $limite);
                    $campos = $this->analizar($texto)['campos'];
                    if ((! isset($campos['emisor']) || ! isset($campos['titular_nombre'])) && microtime(true) + 8 < $limite) {
                        try {
                            $alternativo = $this->comando(['tesseract', $imagen, 'stdout', '-l', 'spa+eng', '--psm', '6', 'quiet'], $limite);
                        } catch (Throwable) {
                            $alternativo = '';
                        }
                        $texto = $this->mejorLectura($texto, $alternativo);
                    }
                    $metodo = 'ocr_local';
                }
                $textos[] = mb_substr($texto, 0, 20000);
                $metodos[] = $metodo;
            }
            $texto = trim(implode("\n\n", $textos));
            if (mb_strlen(preg_replace('/\s+/u', '', $texto)) < 20) {
                throw ValidationException::withMessages(['archivo' => 'No se encontró texto legible. Usa una imagen nítida, derecha y sin recortes, o completa los datos manualmente.']);
            }

            return ['texto' => mb_substr($texto, 0, 40000), 'paginas' => $paginas, 'metodo' => in_array('ocr_local', $metodos, true) ? 'ocr_local' : 'texto_pdf'];
        } finally {
            foreach (glob($directorio.'/*') ?: [] as $temporal) {
                @unlink($temporal);
            }
            @rmdir($directorio);
        }
    }

    public function mejorLectura(string $principal, string $alternativo): string
    {
        $peso = function (string $texto): int {
            $campos = $this->analizar($texto)['campos'];

            return count($campos) + (isset($campos['emisor']) ? 3 : 0) + (isset($campos['titular_nombre']) ? 3 : 0);
        };

        return $peso($alternativo) > $peso($principal) ? $alternativo : $principal;
    }

    private function comando(array $argumentos, float $limite): string
    {
        $restante = $limite - microtime(true);
        if ($restante <= 0) {
            abort(503, 'La lectura superó el tiempo disponible. Reduce las páginas o completa los datos manualmente.');
        }
        try {
            $proceso = new Process($argumentos, env: ['OMP_THREAD_LIMIT' => '1']);
            $proceso->setTimeout(min(15, $restante));
            $proceso->run();
        } catch (Throwable) {
            abort(503, 'No se pudo completar el reconocimiento local. Comprueba las herramientas OCR o usa el registro manual.');
        }
        if (! $proceso->isSuccessful()) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el documento. Comprueba que sea legible y no tenga contraseña. Si persiste, solicita revisar el motor OCR.']);
        }

        return mb_convert_encoding($proceso->getOutput(), 'UTF-8', 'UTF-8');
    }

    private function pareceNombre(string $valor): bool
    {
        $palabras = preg_split('/\s+/u', $valor);

        return (bool) preg_match('/\p{L}{2}/u', $valor) && count($palabras) >= 3 && count($palabras) <= 9 && mb_strlen($valor) <= 150
            && (bool) preg_match('/^[\p{L}\s.\x{0027}’-]+$/u', $valor)
            && ! preg_match('/universidad|instituto|escuela|otorga|licenciad|ingenier|secretar|rector|estudios/iu', $valor);
    }

    private function estudiosBasicos(string $texto): array
    {
        $campos = ['nivel' => 'educacion_basica'];
        $evidencias = ['nivel' => 'Certificado de estudios de educación básica.'];
        $nombre = preg_match('/certificado\s+oficial\s+de\s+estudios/iu', $texto) ? 'Certificado Oficial de Estudios' : 'Certificado de Estudios';
        if (preg_match('/educaci[oó]n\s+b[aá]sica\s+(alternativa|regular)/iu', $texto, $m)) {
            $nombre .= ' — Educación Básica '.mb_convert_case($m[1], MB_CASE_TITLE, 'UTF-8');
        } elseif (preg_match('/\b(primaria|secundaria)\b/iu', $texto, $m)) {
            $nombre .= ' — '.mb_convert_case($m[1], MB_CASE_TITLE, 'UTF-8');
        }
        if (preg_match('/ciclo\s+(inicial|intermedio|avanzado)/iu', $texto, $m)) {
            $nombre .= ' — Ciclo '.mb_convert_case($m[1], MB_CASE_TITLE, 'UTF-8');
        }
        $campos['nombre'] = $nombre;
        $evidencias['nombre'] = mb_substr($texto, 0, 500);
        if (preg_match('/rep[uú]blica\s+del\s+per[uú]/iu', $texto, $m)) {
            $campos['pais'] = 'Perú';
            $evidencias['pais'] = $m[0];
        }
        foreach (preg_split('/\n/u', $texto) as $linea) {
            if (preg_match('/^\s*(?:CEBA|I\.?\s*E\.?|Instituci[oó]n\s+Educativa|Centro\s+de\s+Educaci[oó]n\s+B[aá]sica\s+Alternativa)\s*:\s*([^\n]+)/iu', $linea, $m)) {
                $valor = trim($m[1], " \t.\"_-()");
                if (preg_match('/\p{L}{3}/u', $valor) && ! preg_match('/^nombre|^denominaci[oó]n|^del\s+CEBA|^director/iu', $valor)) {
                    $campos['emisor'] = mb_substr($valor, 0, 200);
                    $evidencias['emisor'] = $linea;
                    break;
                }
            }
        }
        if (preg_match('/^(?:Apellidos\s+y\s+nombres|Estudiante|Alumno|Titular|Perteneciente\s+a)\h*:\h*([^\n]+)/imu', $texto, $m) && $this->pareceNombre(trim($m[1]))) {
            $campos['titular_nombre'] = trim($m[1]);
            $evidencias['titular_nombre'] = $m[0];
        }
        preg_match_all('/SERIE\h*[:\-]?\h*([A-Z])\s*(?:N[.º°o]*|N[uú]mero)\h*[:\-]?\h*([0-9]{3,12})\b/iu', $texto, $series, PREG_SET_ORDER);
        $numeros = [];
        foreach ($series as $serie) {
            $numeros[mb_strtoupper($serie[1]).'-'.$serie[2]] = $serie[0];
        }
        if (count($numeros) === 1) {
            $campos['numero_diploma'] = array_key_first($numeros);
            $evidencias['numero_diploma'] = reset($numeros);
        } elseif ($numeros === [] && preg_match('/(?:certificado\s*(?:N[.º°o]*|n[uú]mero)|N[uú]mero\s+de\s+certificado)\h*[:\-]?\h*([A-Z0-9][A-Z0-9\/-]{2,39})\b/iu', $texto, $m)) {
            $campos['numero_diploma'] = $m[1];
            $evidencias['numero_diploma'] = $m[0];
        }

        return ['campos' => $campos, 'evidencias' => $evidencias];
    }

    public function analizar(string $texto): array
    {
        $texto = str_replace(["\r\n", "\r", "\f"], "\n", $texto);
        $lineas = array_values(array_filter(array_map(fn (string $linea): string => trim(preg_replace('/[\t ]+/u', ' ', $linea)), explode("\n", $texto)), 'strlen'));
        $plano = implode("\n", $lineas);
        $esBasico = (bool) preg_match('/certificado\s+(?:oficial\s+)?de\s+estudios/iu', $plano)
            && (bool) preg_match('/educaci[oó]n\s+b[aá]sica|\bprimaria\b|\bsecundaria\b|\bCEBA\b/iu', $plano);
        $campos = [];
        $evidencias = [];
        $agregar = function (string $campo, string $valor, string $evidencia) use (&$campos, &$evidencias): void {
            $valor = trim($valor, " \t\n\r\0\x0B:;,.\"");
            if ($valor !== '') {
                $campos[$campo] = mb_substr($valor, 0, in_array($campo, ['numero_diploma', 'numero_registro'], true) ? 40 : 200);
                $evidencias[$campo] = mb_substr($evidencia, 0, 500);
            }
        };
        foreach (array_slice($lineas, 0, 25) as $i => $linea) {
            $palabras = preg_split('/\s+/u', preg_replace('/^(?:La|El)\s+/iu', '', $linea));
            $primera = Str::lower(Str::ascii(preg_replace('/[^\p{L}]/u', '', $palabras[0] ?? '')));
            $institucional = collect(['universidad', 'instituto', 'escuela', 'university', 'institute'])->contains(fn (string $palabra): bool => levenshtein($primera, $palabra) <= (strlen($palabra) > 7 ? 2 : 1));
            if (! isset($campos['emisor']) && $institucional && count($palabras) >= 2) {
                $institucion = implode(' ', $palabras);
                for ($j = 1; $j <= 2; $j++) {
                    $siguiente = $lineas[$i + $j] ?? '';
                    if (preg_match('/^(?:de\s*|Nacional\b|Aut[oó]noma\b|Tecnol[oó]gica\b|Superior\b)/iu', $siguiente)
                        && ! preg_match('/otorga|t[ií]tulo|grado|rector|secretar/iu', $siguiente)) {
                        $institucion .= ' '.$siguiente;
                    } else {
                        break;
                    }
                }
                $agregar('emisor', $institucion, $institucion);
            }
        }
        $anclaTitulo = '(?:el\s*)?(?:t[ií]tulo|t[ií]tul[o0]|f[ií]tulo|grado)\s*(?:profesional\s*)?de';
        if (preg_match('/(?:otorga\s*a|confiere\s*a|a\s+favor\s+de|titular\s*:)\s*(.+?)(?=\s*'.$anclaTitulo.'|\s+por\s+haber|$)/isu', $plano, $m)) {
            $titular = preg_replace('/\s+/u', ' ', trim($m[1]));
            if ($this->pareceNombre($titular)) {
                $agregar('titular_nombre', $titular, $m[0]);
            }
        }
        if (! isset($campos['titular_nombre'])) {
            foreach ($lineas as $i => $linea) {
                if ($i > 0 && preg_match('/^'.$anclaTitulo.'\s*$/iu', $linea)) {
                    $candidato = $lineas[$i - 1];
                    if ($this->pareceNombre($candidato)) {
                        $agregar('titular_nombre', $candidato, $candidato.' '.$linea);
                        break;
                    }
                }
            }
        }
        if (preg_match('/(?:el\s+)?(?:t[ií]tulo|grado)(?:\s+profesional|\s+acad[eé]mico)?\s+de\s*[:\n]?\s*(.+?)(?=\n(?:en\s+atenci[oó]n|con\s+reconocimiento|por\s+|otorgado|dado|expedido|registrado)|$)/isu', $plano, $m)) {
            $titulo = preg_replace('/\s+/u', ' ', trim($m[1]));
            if (mb_strlen($titulo) <= 200) {
                $agregar('nombre', $titulo, $m[0]);
            }
        }
        if (! isset($campos['nombre'])) {
            foreach ($lineas as $i => $linea) {
                if (preg_match('/^(?:licenciad[oa]|ingenier[oa]|bachiller|maestr[oa]|doctor[ao]?|profesional\s+t[eé]cnico|t[eé]cnico)\b.+/iu', $linea)) {
                    $titulo = $linea;
                    if (isset($lineas[$i + 1]) && preg_match('/(?:\by\s*|\bde\s*)$/iu', $linea)) {
                        $titulo .= ' '.$lineas[$i + 1];
                    }
                    $agregar('nombre', $titulo, $titulo);
                    break;
                }
            }
        }
        if (isset($campos['nombre'])) {
            $titulo = Str::lower(Str::ascii($campos['nombre']));
            $nivel = match (true) {
                (bool) preg_match('/\bdoctor(?:ado|a)?\b/', $titulo) => 'doctorado',
                (bool) preg_match('/\bmaestr(?:ia|o|a)\b|\bmaster\b/', $titulo) => 'maestria',
                (bool) preg_match('/\bbachiller\b/', $titulo) => 'bachiller',
                (bool) preg_match('/\btecnic[oa]\b/', $titulo) => 'tecnico',
                (bool) preg_match('/\blicenciad[oa]\b|\bingenier[oa]\b|\babogad[oa]\b|\bmedic[oa]\b/', $titulo) => 'titulo_profesional',
                default => null,
            };
            if ($nivel) {
                $agregar('nivel', $nivel, $campos['nombre']);
            }
        }
        $fechas = [];
        $meses = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
        $patron = '/\b(\d{1,2})\s+de\s+('.implode('|', array_keys($meses)).')\s+(?:de[l]?\s+)?(\d{4})\b/iu';
        preg_match_all($patron, $plano, $coincidencias, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($coincidencias as $m) {
            $mes = $meses[mb_strtolower($m[2][0])];
            if (! checkdate($mes, (int) $m[1][0], (int) $m[3][0])) {
                continue;
            }
            $contexto = mb_substr(substr($plano, 0, $m[0][1]), -160).' '.$m[0][0];
            $segmentos = preg_split('/\n(?=(?:Dado\s+en|Expedido|Fecha\s+de\s+(?:emisi[oó]n|expedici[oó]n)|Ecatepec|Ciudad\s+de))/iu', $contexto);
            $contexto = end($segmentos);
            $fecha = sprintf('%04d-%02d-%02d', $m[3][0], $mes, $m[1][0]);
            $fechas[] = ['fecha' => $fecha, 'contexto' => trim($contexto)];
            if (preg_match('/(?:dado\s+en|expedido|expedici[oó]n|emisi[oó]n|otorgado|ecatepec|ciudad\s+de\s+m[eé]xico)/iu', $contexto)
                && ! preg_match('/examen|termin[oó]|culmin[oó]|acuerdo|diario\s+oficial/iu', $contexto)) {
                $agregar('fecha_emision', $fecha, $contexto);
            }
        }
        foreach ($lineas as $linea) {
            if (preg_match('/(?:fecha\s+de\s+)?(?:emisi[oó]n|expedici[oó]n)\s*[:\-]?\s*(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/iu', $linea, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                $agregar('fecha_emision', sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]), $linea);
            }
            if (preg_match('/(?:diploma|t[ií]tulo)\s*(?:n[.º°oúumero]*|n[uú]mero|no\.)\s*[:\-]?\s*([A-Z0-9][A-Z0-9\/\-]{2,39})\b/iu', $linea, $m)) {
                $agregar('numero_diploma', $m[1], $linea);
            }
            if (preg_match('/(?:registro\s+(?:SUNEDU|MINEDU)|c[oó]digo\s+MINEDU)\s*[:#\-]?\s*([A-Z0-9][A-Z0-9\/\-]{2,39})\b/iu', $linea, $m)) {
                $agregar('numero_registro', $m[1], $linea);
            }
        }
        $institucion = Str::lower(Str::ascii($campos['emisor'] ?? ''));
        if (preg_match('/\bmexico\b/', $institucion)) {
            $agregar('pais', 'México', $campos['emisor']);
        } elseif (preg_match('/\bperu\b/', $institucion)) {
            $agregar('pais', 'Perú', $campos['emisor']);
        }
        if (isset($campos['fecha_emision'])) {
            $agregar('fecha_obtencion', $campos['fecha_emision'], $evidencias['fecha_emision']);
        }
        if ($esBasico) {
            $basico = $this->estudiosBasicos($plano);
            unset($campos['emisor'], $campos['titular_nombre'], $campos['numero_diploma'], $campos['numero_registro']);
            unset($evidencias['emisor'], $evidencias['titular_nombre'], $evidencias['numero_diploma'], $evidencias['numero_registro']);
            $campos = [...$campos, ...$basico['campos']];
            $evidencias = [...$evidencias, ...$basico['evidencias']];
        }
        $faltantes = array_values(array_diff(['titular_nombre', 'nombre', 'emisor', 'fecha_emision', 'nivel', 'pais', 'numero_diploma', 'numero_registro'], array_keys($campos)));
        $avisos = ['Los datos son sugerencias: contrástalos con el documento antes de aplicarlos. Esta lectura no acredita autenticidad ni registro oficial.'];
        if (isset($campos['pais']) && $campos['pais'] !== 'Perú') {
            $avisos[] = 'Documento extranjero. No se presume reconocimiento o revalidación en Perú; la consulta de institutos MINEDU no corresponde a títulos universitarios extranjeros.';
        }
        if ($esBasico) {
            $avisos[] = 'El encabezado del Ministerio de Educación no identifica al centro emisor. Comprueba el CEBA o institución educativa, titular, calificaciones y firmas; esta lectura no certifica que se hayan concluido los estudios.';
            if (! isset($campos['titular_nombre']) || ! isset($campos['emisor'])) {
                $avisos[] = 'No se detectaron todos los datos personales y del centro educativo. Puede tratarse de un formato sin completar; no lo uses como acreditación de estudios.';
            }
        }
        if (count($fechas) > 1) {
            $avisos[] = 'Se encontraron varias fechas. Revisa la fecha de expedición; las fechas de examen, fin de estudios y acuerdos no son equivalentes.';
        }

        return ['campos' => $campos, 'evidencias' => $evidencias, 'faltantes' => $faltantes, 'fechas_detectadas' => $fechas, 'avisos' => $avisos];
    }
}
