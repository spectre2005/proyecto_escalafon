<?php

namespace App\Support\Documentos;

use Illuminate\Support\Str;

/** Extrae la comunicación enviada; no presume envío a partir de la fecha de un título. */
class ReconocimientoConsulta
{
    public function analizar(string $texto): array
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $campos = [];
        $evidencias = [];
        $poner = function (string $campo, string $valor, string $referencia) use (&$campos, &$evidencias): void {
            $maximo = match ($campo) {
                'entidad_consultada', 'destinatario', 'nombre_registro' => 254,
                'remitente' => 254, 'asunto', 'criterio_busqueda' => 250, 'url_registro' => 1000, 'resultado_consulta' => 2000,
                'cargo_destinatario' => 150, 'expediente' => 80, default => 40,
            };
            $campos[$campo] = trim(mb_substr($valor, 0, $maximo));
            $evidencias[$campo] = mb_substr(trim($referencia), 0, 400);
        };
        if (preg_match('/^(?:Entidad\s+(?:consultada|destinataria)|Destinatario|Para)\s*:\s*(.+)$/imu', $texto, $m)
            && preg_match('/universidad|instituto|escuela|SUNEDU|MINEDU|SERVIR|direcci[oó]n|ministerio/iu', $m[1])) {
            $poner('entidad_consultada', $m[1], $m[0]);
        } elseif (preg_match('/(?:Se[nñ]or(?:a)?|Destinatario)\s*:?[^\n]*\n(?:[^\n]*rector[^\n]*\n)?((?:Universidad|Instituto|Escuela|SUNEDU|MINEDU|SERVIR)\b[^\n]+)/iu', $texto, $m)) {
            $poner('entidad_consultada', $m[1], $m[0]);
        }
        $correo = (bool) preg_match('/^De\s*:\s*.+/imu', $texto) && (bool) preg_match('/^(?:Para|Enviado|Fecha)\s*:/imu', $texto);
        if ($correo) {
            $poner('medio', 'correo', 'Se identificaron cabeceras de correo. Comprueba el envío y sus destinatarios.');
        }
        if (! $correo && preg_match('/\bOFICIO\s*(?:N(?:[.º°o]|ro|[uú]mero)*\s*)?[:#\-]?\s*(\d[A-Z0-9.\/\-]*(?:\s*[-–]\s*[A-Z0-9.\/\-]+)*)/iu', $texto, $m)) {
            $referencia = preg_replace('/\s*[-–]\s*/u', '-', trim($m[1]));
            if (mb_strlen($referencia) <= 40) {
                $poner('numero_oficio', $referencia, $m[0]);
            }
            if (! $correo) {
                $poner('medio', 'oficio', $m[0]);
            }
        } elseif ($correo && preg_match('/^(?:Referencia|Message-ID|Identificador del mensaje)\s*:\s*(.{1,40})$/imu', $texto, $m)) {
            $poner('numero_oficio', $m[1], $m[0]);
        }
        if ($correo) {
            foreach (['De' => 'remitente', 'Para' => 'destinatario'] as $cabecera => $campo) {
                if (preg_match('/^'.$cabecera.'\h*:\h*([^\n]+)/imu', $texto, $m)) {
                    preg_match_all('/[A-Z0-9.!#$%&\x{0027}*+\/=\?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', $m[1], $direcciones);
                    $validas = array_values(array_unique(array_filter($direcciones[0], fn (string $email): bool => (bool) filter_var($email, FILTER_VALIDATE_EMAIL))));
                    if (count($validas) === 1) {
                        $poner($campo, $validas[0], $m[0]);
                    }
                }
            }
            if (preg_match('/^Asunto\h*:\h*([^\n]+)/imu', $texto, $m)) {
                $poner('asunto', $m[1], $m[0]);
            }
        } else {
            foreach (['Destinatario' => 'destinatario', 'Cargo del destinatario' => 'cargo_destinatario', '(?:Expediente|Número de expediente|Cargo de recepción)' => 'expediente', '(?:Registro oficial|Nombre del registro|Portal consultado)' => 'nombre_registro', '(?:URL del registro|URL|Dirección web)' => 'url_registro', '(?:Criterio de búsqueda|Criterio utilizado)' => 'criterio_busqueda', '(?:Resultado de consulta|Resultado observado)' => 'resultado_consulta'] as $etiqueta => $campo) {
                if (preg_match('/^'.$etiqueta.'\h*:\h*([^\n]+)/imu', $texto, $m)) {
                    $valor = trim($m[1]);
                    if ($campo !== 'url_registro' || (filter_var($valor, FILTER_VALIDATE_URL) && in_array(parse_url($valor, PHP_URL_SCHEME), ['https', 'http'], true))) {
                        $poner($campo, $valor, $m[0]);
                    }
                }
            }
            if (isset($campos['nombre_registro'], $campos['url_registro'])) {
                $poner('medio', 'registro_oficial', 'Se identificaron el nombre y la URL del registro consultado.');
            }
            if (preg_match('/^Canal de envío\h*:\h*([^\n]+)/imu', $texto, $m)) {
                $canales = ['mesa de partes física' => 'mesa_partes_fisica', 'mesa de partes virtual' => 'mesa_partes_virtual', 'correo institucional' => 'correo', 'otro' => 'otro'];
                $canal = $canales[mb_strtolower(trim($m[1]))] ?? null;
                if ($canal) {
                    $poner('canal_envio', $canal, $m[0]);
                }
            }
        }
        $fechas = [];
        foreach (preg_split('/\n/u', $texto) as $linea) {
            if (! preg_match('/(?:fecha\s+de\s+env[ií]o|enviado(?:\s+el)?|fecha\s+de\s+consulta|consultado\s+el|^Fecha\s*:)/iu', $linea)) {
                continue;
            }
            $fecha = null;
            if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $linea, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $fecha = $m[0];
            } elseif (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/', $linea, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                $fecha = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            } else {
                $detectadas = app(ReconocimientoTitulo::class)->analizar($linea)['fechas_detectadas'];
                if (count($detectadas) === 1) {
                    $fecha = $detectadas[0]['fecha'];
                }
            }
            if ($fecha) {
                $fechas[$fecha] = $linea;
            }
        }
        if (count($fechas) === 1) {
            $poner('fecha_envio', array_key_first($fechas), reset($fechas));
        }
        if (isset($campos['entidad_consultada'])) {
            $entidad = Str::lower(Str::ascii($campos['entidad_consultada']));
            foreach (['sunedu' => 'sunedu', 'minedu' => 'minedu', 'servir' => 'rnssc'] as $palabra => $fuente) {
                if (preg_match('/\b'.$palabra.'\b/', $entidad)) {
                    $poner('fuente', $fuente, $evidencias['entidad_consultada']);
                    break;
                }
            }
        }

        return ['campos' => $campos, 'evidencias' => $evidencias, 'avisos' => [
            'Revisa los datos antes de aplicarlos. Una fecha de expedición no demuestra que la consulta haya sido enviada.',
            ...(count($fechas) > 1 ? ['Hay varias fechas de envío o consulta. Selecciona la correcta manualmente.'] : []),
        ]];
    }
}
