<?php

namespace App\Support;

use App\Models\NotificacionUsuario;
use App\Models\Plataforma\Institucion;
use App\Models\RequerimientoDocumento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Asunto, texto plano y HTML de los correos de requerimientos (solicitud y recordatorio), con la misma información en
 * ambas versiones. El asunto empieza siempre con [REQ-<uuid>]: RecepcionGmail relaciona la respuesta por esa referencia.
 * Solo usa datos registrados (configuración de la institución, titular, requerimiento y límites reales de los
 * adjuntos); lo que falta se omite, no se inventa.
 */
final class MensajeRequerimiento
{
    public const TITULO_SOLICITUD = 'Requerimiento de documentación';

    public const TITULO_RECORDATORIO = 'Seguimiento de tu requerimiento';

    /**
     * Valores por defecto de la configuración (InstitutionSettingsController y config/institution.js). El formulario
     * los trae precargados y el área es obligatoria: guardarlos sin cambiar no confirma el nombre real, así que se omiten.
     */
    private const NOMBRE_SIN_CONFIGURAR = 'Mi institución';

    private const AREA_SIN_CONFIGURAR = 'Gestión de personas';

    private const COLOR_POR_DEFECTO = '#0f766e';

    public static function referencia(RequerimientoDocumento $r): string
    {
        return '[REQ-'.$r->id.']';
    }

    /** @return array{asunto: string, texto: string, html: string} */
    public static function construir(NotificacionUsuario $n, RequerimientoDocumento $r): array
    {
        $r->loadMissing(['usuario:id,nombres,apellido_paterno,apellido_materno', 'tipoDocumento:id,nombre,seccion_legajo_id', 'tipoDocumento.seccionLegajo:id,nombre']);
        $datos = self::datos($n, $r);

        return ['asunto' => self::asunto($datos), 'texto' => self::texto($datos), 'html' => self::html($datos)];
    }

    /** @return array<string, mixed> */
    private static function datos(NotificacionUsuario $n, RequerimientoDocumento $r): array
    {
        $institucion = self::institucion();
        $documento = self::linea($r->tipoDocumento?->nombre);
        $seccion = $r->tipoDocumento?->seccionLegajo;
        $descripcion = self::linea($r->descripcion);
        // El alta guarda «<Documento>: <detalle>»; el detalle se muestra sin repetir el documento.
        if ($documento !== '' && str_starts_with($descripcion, $documento.': ')) {
            $descripcion = trim(mb_substr($descripcion, mb_strlen($documento) + 2));
        }
        $mensaje = trim(str_replace(["\r\n", "\r"], "\n", (string) $n->mensaje));
        $recordatorio = $n->titulo === self::TITULO_RECORDATORIO;
        $kb = Parametros::entero('tamano_maximo_pdf_kb');

        return [
            'recordatorio' => $recordatorio,
            'referencia' => self::referencia($r),
            'institucion' => $institucion['nombre'],
            'oficina' => $institucion['oficina'],
            'color' => $institucion['color'],
            'titular' => self::linea(implode(' ', [$r->usuario?->nombres, $r->usuario?->apellido_paterno, $r->usuario?->apellido_materno])),
            'documento' => $documento,
            'seccion' => $seccion ? self::linea(str_pad((string) $seccion->id, 2, '0', STR_PAD_LEFT).' · '.$seccion->nombre) : '',
            'descripcion' => $descripcion,
            // En el alta el mensaje es la misma descripción; solo se muestra si aporta algo (p. ej. el texto del recordatorio).
            'mensaje' => $mensaje !== '' && ! in_array(self::linea($mensaje), [self::linea($r->descripcion), $descripcion], true) ? $mensaje : '',
            // Fecha vigente con la regla actual del sistema: la prórroga registrada, si existe; si no, la fecha límite.
            'vence' => ($r->prorroga_hasta ?? $r->fecha_limite)?->format('d/m/Y'),
            'vence_original' => $r->prorroga_hasta ? $r->fecha_limite?->format('d/m/Y') : null,
            'tamano' => $kb > 0 ? ($kb % 1024 === 0 ? ($kb / 1024).' MB' : $kb.' KB') : null,
            // Con lectura de Gmail la respuesta se incorpora sola (hasta MAXIMO_ADJUNTOS PDF); sin ella, el personal la
            // registra a mano con un solo PDF (SolicitudLegajoController::registrarEntrega).
            'recepcion_automatica' => GmailCorreo::conexion() && GmailCorreo::recepcionLista(),
        ];
    }

    /**
     * Nombre y oficina guardados en la configuración de la institución (su propia base, en ambos modos); en modo
     * subdominio, si aún no se configuró, el nombre registrado en el panel.
     *
     * @return array{nombre: string, oficina: string, color: string}
     */
    private static function institucion(): array
    {
        $guardado = [];
        try {
            if (Schema::hasTable('institution_settings')) {
                $fila = DB::table('institution_settings')->where('id', 1)->value('settings');
                $guardado = is_string($fila) ? (array) json_decode($fila, true) : [];
            }
        } catch (Throwable) {
            $guardado = [];
        }
        $nombre = self::linea(is_string($guardado['name'] ?? null) ? $guardado['name'] : '');
        if ($nombre === '' || $nombre === self::NOMBRE_SIN_CONFIGURAR) {
            $nombre = app()->bound(Institucion::class) ? self::linea(app(Institucion::class)->nombre) : '';
        }
        $color = is_string($guardado['reportColor'] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $guardado['reportColor']) ? $guardado['reportColor'] : self::COLOR_POR_DEFECTO;

        $oficina = self::linea(is_string($guardado['area'] ?? null) ? $guardado['area'] : '');

        return ['nombre' => $nombre, 'oficina' => $oficina === self::AREA_SIN_CONFIGURAR ? '' : $oficina, 'color' => $color];
    }

    /** @param array<string, mixed> $d */
    private static function asunto(array $d): string
    {
        $partes = array_filter([
            self::recortar($d['institucion'], 60),
            ($d['recordatorio'] ? 'Seguimiento de requerimiento' : self::TITULO_SOLICITUD).($d['documento'] !== '' ? ': '.self::recortar($d['documento'], 70) : ''),
            $d['vence'] ? 'vence '.$d['vence'] : '',
        ]);

        return $d['referencia'].' '.implode(' · ', $partes);
    }

    /** @param array<string, mixed> $d */
    private static function texto(array $d): string
    {
        $l = array_values(array_filter([$d['institucion'], $d['oficina']]));
        if ($l !== []) {
            $l[] = '';
        }
        $l[] = $d['titular'] !== '' ? 'Buen día, '.$d['titular'].':' : 'Buen día:';
        $l[] = '';
        $l[] = self::introduccion($d);
        $l[] = '';
        $l[] = mb_strtoupper(self::tituloSolicitud($d));
        foreach (self::filas($d) as $etiqueta => $valor) {
            $l[] = $etiqueta.': '.$valor;
        }
        if ($d['mensaje'] !== '') {
            $l[] = '';
            $l[] = 'MENSAJE';
            $l[] = $d['mensaje'];
        }
        $l[] = '';
        $l[] = 'CÓMO RESPONDER';
        foreach (self::instrucciones($d) as $instruccion) {
            $l[] = '- '.$instruccion;
        }
        $l[] = '';
        $l[] = 'DESPUÉS DE RESPONDER';
        $l[] = self::despues($d);
        $l[] = '';
        $l[] = '--';
        $l[] = self::pie($d);
        $l[] = 'Referencia: '.$d['referencia'];

        return implode("\n", $l)."\n";
    }

    /** @param array<string, mixed> $d */
    private static function html(array $d): string
    {
        $c = e($d['color']);
        $fuente = 'font-family:Arial,Helvetica,sans-serif;';
        $filas = '';
        foreach (self::filas($d) as $etiqueta => $valor) {
            $filas .= '<tr><td style="'.$fuente.'padding:6px 12px 6px 0;font-size:13px;color:#64748b;vertical-align:top;white-space:nowrap;">'.e($etiqueta).'</td>'
                .'<td style="'.$fuente.'padding:6px 0;font-size:14px;color:#1e293b;vertical-align:top;'.($etiqueta === 'Fecha límite' ? 'font-weight:bold;' : '').'">'.e($valor).'</td></tr>';
        }
        $instrucciones = '';
        foreach (self::instrucciones($d) as $instruccion) {
            $instrucciones .= '<li style="margin:0 0 6px;">'.e($instruccion).'</li>';
        }
        $encabezado = $d['institucion'] !== '' || $d['oficina'] !== ''
            ? '<tr><td style="'.$fuente.'padding:20px 24px 16px;border-bottom:1px solid #e2e8f0;">'
                .($d['institucion'] !== '' ? '<div style="font-size:16px;font-weight:bold;color:#1e293b;">'.e($d['institucion']).'</div>' : '')
                .($d['oficina'] !== '' ? '<div style="font-size:13px;color:#64748b;margin-top:2px;">'.e($d['oficina']).'</div>' : '')
                .'</td></tr>'
            : '';
        $mensaje = $d['mensaje'] !== ''
            ? '<p style="margin:0 0 6px;font-size:13px;font-weight:bold;color:#1e293b;">Mensaje</p>'
                .'<p style="margin:0 0 20px;padding:12px;background:#f8fafc;border-left:3px solid '.$c.';font-size:14px;line-height:1.5;color:#334155;">'.nl2br(e($d['mensaje']), false).'</p>'
            : '';

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.e(self::tituloSolicitud($d)).'</title></head>'
            .'<body style="margin:0;padding:0;background:#f1f5f9;">'
            .'<div style="display:none;max-height:0;overflow:hidden;">'.e(self::introduccion($d)).'</div>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;"><tr><td align="center" style="padding:24px 12px;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border:1px solid #e2e8f0;border-top:4px solid '.$c.';border-radius:6px;">'
            .$encabezado
            .'<tr><td style="'.$fuente.'padding:24px;color:#334155;font-size:14px;line-height:1.5;">'
            .'<p style="margin:0 0 12px;">'.e($d['titular'] !== '' ? 'Buen día, '.$d['titular'].':' : 'Buen día:').'</p>'
            .'<p style="margin:0 0 20px;">'.e(self::introduccion($d)).'</p>'
            .'<p style="margin:0 0 6px;font-size:13px;font-weight:bold;color:#1e293b;">'.e(self::tituloSolicitud($d)).'</p>'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">'.$filas.'</table>'
            .$mensaje
            .'<p style="margin:0 0 6px;font-size:13px;font-weight:bold;color:#1e293b;">Cómo responder</p>'
            .'<ul style="margin:0 0 20px;padding-left:20px;">'.$instrucciones.'</ul>'
            .'<p style="margin:0 0 6px;font-size:13px;font-weight:bold;color:#1e293b;">Después de responder</p>'
            .'<p style="margin:0;">'.e(self::despues($d)).'</p>'
            .'</td></tr>'
            .'<tr><td style="'.$fuente.'padding:16px 24px;border-top:1px solid #e2e8f0;font-size:12px;line-height:1.5;color:#64748b;">'
            .e(self::pie($d)).'<br>Referencia: '.e($d['referencia'])
            .'</td></tr></table></td></tr></table></body></html>';
    }

    /** @param array<string, mixed> $d */
    private static function introduccion(array $d): string
    {
        // La oficina va en el encabezado: anteponerla aquí exigiría adivinar su artículo («la Unidad», «el Departamento»).
        return $d['recordatorio']
            ? 'Te recordamos que tienes un requerimiento de documentación pendiente.'
            : 'Te solicitamos la documentación que se detalla a continuación para tu legajo.';
    }

    /** @param array<string, mixed> $d */
    private static function tituloSolicitud(array $d): string
    {
        return $d['recordatorio'] ? 'Requerimiento pendiente' : 'Documentación solicitada';
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, string>
     */
    private static function filas(array $d): array
    {
        $vence = $d['vence'] ? $d['vence'].($d['vence_original'] ? ' (plazo prorrogado; fecha original '.$d['vence_original'].')' : '') : '';

        return array_filter([
            'Documento' => $d['documento'],
            // El recordatorio es breve: identifica el requerimiento sin repetir la solicitud completa.
            'Sección' => $d['recordatorio'] ? '' : $d['seccion'],
            'Detalle' => $d['recordatorio'] && $d['documento'] !== '' ? '' : $d['descripcion'],
            'Fecha límite' => $vence,
        ], fn (string $valor): bool => $valor !== '');
    }

    /**
     * @param  array<string, mixed>  $d
     * @return list<string>
     */
    private static function instrucciones(array $d): array
    {
        $adjuntos = $d['recepcion_automatica']
            ? 'Adjunta los documentos en formato PDF'.($d['tamano'] ? ', de hasta '.$d['tamano'].' cada uno' : '')
                .', con un máximo de '.RecepcionGmail::MAXIMO_ADJUNTOS.' archivos por correo. Los archivos en otro formato no se incorporan.'
            : 'Adjunta el documento en un solo archivo PDF'.($d['tamano'] ? ' de hasta '.$d['tamano'] : '').'.';

        return array_values(array_filter([
            // El remitente debe coincidir con el correo del titular, tanto en la recepción automática como en la manual.
            'Responde a este mismo correo, desde la dirección en la que lo recibiste, y adjunta el documento solicitado o explica por qué no corresponde.',
            $adjuntos,
            'Conserva la referencia '.$d['referencia'].' en el asunto para identificar tu entrega.',
            $d['recordatorio'] ? null : 'No necesitas ingresar al sistema ni crear una cuenta.',
        ]));
    }

    /** @param array<string, mixed> $d */
    private static function despues(array $d): string
    {
        // No promete un procesamiento automático: en ambos casos la revisión la hace el personal.
        return 'Los documentos recibidos serán revisados por el área responsable de tu legajo'.($d['oficina'] !== '' ? ' ('.$d['oficina'].')' : '').'. '
            .'Responder este correo no significa que el documento haya sido aceptado.';
    }

    /** @param array<string, mixed> $d */
    private static function pie(array $d): string
    {
        return 'Este es un mensaje automático'.($d['institucion'] !== '' ? ' de '.$d['institucion'] : '').'. '
            .'Responde solo con lo solicitado y no envíes información personal sensible que no se te haya pedido.';
    }

    /** Una línea sin saltos ni caracteres de control (para asunto y campos breves). */
    private static function linea(?string $valor): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $valor)));
    }

    private static function recortar(string $valor, int $maximo): string
    {
        return mb_strlen($valor) > $maximo ? rtrim(mb_substr($valor, 0, $maximo - 1)).'…' : $valor;
    }
}
