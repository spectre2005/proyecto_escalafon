<?php

namespace App\Support;

use App\Models\RequerimientoDocumento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Throwable;

final class RecepcionGmail
{
    public static function sincronizar(): int
    {
        if (! GmailCorreo::recepcionLista()) {
            return 0;
        }
        $conexion = GmailCorreo::conexion();
        $hasta = now();
        $desde = $conexion->recepcion_revisada_en?->copy()->subDays(2)
            ?? Carbon::parse(DB::table('notificaciones_usuario')->whereNotNull('correo_enviado_en')->min('correo_enviado_en') ?? now()->subDays(30))->subDay();
        $pagina = null;
        $total = 0;
        try {
            do {
                $lista = GmailCorreo::leer('messages', array_filter(['q' => 'subject:REQ- -in:sent -in:drafts after:'.$desde->timestamp.' before:'.$hasta->timestamp,
                    'maxResults' => 100, 'pageToken' => $pagina]));
                foreach ($lista['messages'] ?? [] as $mensaje) {
                    $id = (string) ($mensaje['id'] ?? '');
                    if (! preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $id) || self::registrado($conexion->email, $id)) {
                        continue;
                    }
                    $total += self::importar(GmailCorreo::leer('messages/'.$id, ['format' => 'full']), $conexion->email, $id);
                }
                $pagina = $lista['nextPageToken'] ?? null;
            } while ($pagina);
            $conexion->update(['recepcion_revisada_en' => $hasta, 'recepcion_error' => null]);
        } catch (Throwable) {
            $conexion->update(['recepcion_error' => 'No se pudo completar la recepción. Revisa la conexión de Gmail; se reintentará sin duplicar entregas.']);
            throw new RuntimeException('No se pudo completar la recepción de Gmail.');
        }

        return $total;
    }

    private static function registrado(string $buzon, string $id): bool
    {
        return DB::table('correos_recibidos')->where('buzon', $buzon)->where('mensaje_id', $id)->exists();
    }

    private static function registrar(string $buzon, string $id, ?string $requerimiento, string $estado, ?string $motivo = null): void
    {
        DB::table('correos_recibidos')->insertOrIgnore(['id' => (string) Str::uuid(), 'buzon' => $buzon, 'mensaje_id' => $id,
            'requerimiento_id' => $requerimiento, 'estado' => $estado, 'motivo' => $motivo, 'created_at' => now(), 'updated_at' => now()]);
    }

    private static function importar(array $mensaje, string $buzon, string $id): int
    {
        $headers = [];
        foreach ($mensaje['payload']['headers'] ?? [] as $header) {
            $headers[strtolower($header['name'])][] = $header['value'];
        }
        $asunto = $headers['subject'][0] ?? '';
        preg_match_all('/\[REQ-([a-f0-9-]{36})\]/i', $asunto, $referencias);
        $ids = array_unique($referencias[1] ?? []);
        $referencia = count($ids) === 1 ? strtolower(reset($ids)) : '';
        $r = Str::isUuid($referencia) ? RequerimientoDocumento::query()->find($referencia) : null;
        try {
            $remitente = mb_strtolower(Address::create($headers['from'][0] ?? '')->getAddress());
        } catch (Throwable) {
            $remitente = '';
        }
        $recibido = isset($mensaje['internalDate']) ? Carbon::createFromTimestampMs((int) $mensaje['internalDate']) : null;
        $motivo = null;
        if (! $r || ! $recibido || array_intersect($mensaje['labelIds'] ?? [], ['SENT', 'DRAFT', 'SPAM', 'TRASH'])) {
            $motivo = 'No es una respuesta de requerimiento identificable.';
        } elseif ($remitente !== mb_strtolower((string) $r->usuario()->value('email_institucional'))) {
            $motivo = 'El remitente no coincide con el titular del requerimiento.';
        } elseif (! self::autenticado($headers)) {
            $motivo = 'Gmail no confirmó la autenticidad del remitente.';
        } elseif (! $r->notificacionesUsuario()->where('correo_estado', 'enviado')->where('correo_enviado_en', '<=', $recibido)->exists()) {
            $motivo = 'No hay un requerimiento enviado antes de esta respuesta.';
        }
        if ($motivo) {
            self::registrar($buzon, $id, $r?->id, 'ignorado', $motivo);

            return 0;
        }
        $archivosGuardados = [];
        $disco = (string) config('legajo.disco_documentos');
        try {
            return DB::transaction(function () use ($r, $mensaje, $buzon, $id, $remitente, $asunto, $recibido, $disco, &$archivosGuardados): int {
                $r = RequerimientoDocumento::query()->lockForUpdate()->findOrFail($r->id);
                if (self::registrado($buzon, $id)) {
                    return 0;
                }
                if ($r->cerrado_en || ! in_array($r->estado, ['abierto', 'observado', 'en_revision'], true)
                    || ! $r->usuario()->whereHas('legajos', fn ($q) => $q->where('estado_legajo', 'activo'))->exists()) {
                    self::registrar($buzon, $id, $r->id, 'ignorado', 'El requerimiento o legajo están cerrados.');

                    return 0;
                }
                $partes = self::partes($mensaje['payload'] ?? []);
                $texto = [];
                $html = [];
                $archivos = [];
                $avisos = [];
                $adjuntos = 0;
                $maximo = Parametros::entero('tamano_maximo_pdf_kb') * 1024;
                foreach ($partes as $parte) {
                    $nombre = basename(str_replace('\\', '/', (string) ($parte['filename'] ?? '')));
                    if ($nombre === '' && in_array($parte['mimeType'] ?? '', ['text/plain', 'text/html'], true)) {
                        $contenido = self::contenido($parte, $id, 200000);
                        if (($parte['mimeType'] ?? '') === 'text/plain') {
                            $texto[] = $contenido;
                        } else {
                            $html[] = $contenido;
                        }

                        continue;
                    }
                    if ($nombre === '') {
                        continue;
                    }
                    $adjuntos++;
                    if ($adjuntos > 10 || strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'pdf' || ($parte['body']['size'] ?? 0) > $maximo) {
                        $avisos[] = mb_substr($nombre, 0, 150).': no incorporado (solo PDF, hasta '.($maximo / 1024).' KB y 10 adjuntos por correo).';

                        continue;
                    }
                    $contenido = self::contenido($parte, $id, $maximo);
                    if (! str_contains(substr($contenido, 0, 1024), '%PDF-') || (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenido) !== 'application/pdf') {
                        $avisos[] = mb_substr($nombre, 0, 150).': el contenido no es un PDF válido.';

                        continue;
                    }
                    $ruta = 'solicitudes/'.$r->id.'/'.Str::uuid().'.pdf';
                    $archivosGuardados[] = $ruta;
                    if (! Storage::disk($disco)->put($ruta, $contenido)) {
                        throw new RuntimeException('No se pudo guardar el adjunto.');
                    }
                    $archivos[] = ['archivo' => mb_substr($nombre, 0, 255), 'ruta' => $ruta, 'disco' => $disco, 'hash' => hash('sha256', $contenido)];
                }
                $mensajeTexto = $texto ? implode("\n", $texto) : html_entity_decode(strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', implode("\n", $html))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $mensajeTexto = mb_substr(mb_convert_encoding($mensajeTexto, 'UTF-8', 'UTF-8'), 0, 20000);
                $evento = ['accion' => 'respuesta', 'canal' => 'gmail', 'mensaje' => trim($mensajeTexto) ?: 'El docente envió una respuesta sin texto.',
                    'remitente' => $remitente, 'referencia_correo' => mb_substr($asunto, 0, 300), 'gmail_id' => $id,
                    'autor' => null, 'fecha' => $recibido->toIso8601String(), 'registrado_en' => now()->toIso8601String(),
                    'archivos' => $archivos, 'avisos_adjuntos' => $avisos];
                $observacion = collect($r->seguimiento ?? [])->where('accion', 'observar')->last();
                $estado = $observacion && Carbon::parse($observacion['fecha'])->gte($recibido) ? $r->estado : 'en_revision';
                $historial = collect([...($r->seguimiento ?? []), $evento])->sortBy(fn (array $e) => Carbon::parse($e['fecha'] ?? now())->getTimestamp())->values()->all();
                $r->update(['estado' => $estado, 'seguimiento' => $historial]);
                self::registrar($buzon, $id, $r->id, 'importado');

                return 1;
            });
        } catch (Throwable $e) {
            foreach ($archivosGuardados as $ruta) {
                Storage::disk($disco)->delete($ruta);
            }
            throw $e;
        }
    }

    /** Usa el primer resultado que Gmail agrega al recibir el mensaje, no cabeceras insertadas en el texto. */
    private static function autenticado(array $headers): bool
    {
        $resultado = $headers['authentication-results'][0] ?? '';

        return preg_match('/^mx\.google\.com\s*;/i', $resultado) === 1
            && preg_match('/\bdmarc=pass\b/i', $resultado) === 1;
    }

    private static function partes(array $parte): array
    {
        $resultado = [$parte];
        foreach ($parte['parts'] ?? [] as $hija) {
            $resultado = [...$resultado, ...self::partes($hija)];
        }

        return $resultado;
    }

    private static function contenido(array $parte, string $mensaje, int $maximo): string
    {
        if (($parte['body']['size'] ?? 0) > $maximo) {
            return '';
        }
        $body = $parte['body'] ?? [];
        if (isset($body['attachmentId'])) {
            $body = GmailCorreo::leer('messages/'.$mensaje.'/attachments/'.rawurlencode($body['attachmentId']));
        }
        $contenido = base64_decode(strtr((string) ($body['data'] ?? ''), '-_', '+/'), true);
        if ($contenido === false || strlen($contenido) > $maximo) {
            return '';
        }

        return $contenido;
    }
}
