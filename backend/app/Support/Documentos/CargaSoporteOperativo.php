<?php

namespace App\Support\Documentos;

use App\Models\ArchivoDocumento;
use App\Support\Parametros;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CargaSoporteOperativo
{
    /** Sustento de una operación; no altera ni folia documentos de un legajo cerrado. */
    public static function guardar(UploadedFile $pdf, string $autor): ArchivoDocumento
    {
        if (! ArchivoPdf::esPdf($pdf) || $pdf->getSize() > Parametros::entero('tamano_maximo_pdf_kb') * 1024) {
            throw ValidationException::withMessages(['inventario_pdf' => 'Adjunta un PDF válido dentro del tamaño máximo configurado.']);
        }
        $contenido = file_get_contents($pdf->getRealPath());
        abort_if($contenido === false, 503, 'No se pudo leer el inventario.');
        try {
            app(AnalizadorAntivirus::class)->analizar($contenido);
        } catch (ValidationException $error) {
            Log::warning('Inventario rechazado por antivirus', ['actor_usuario_id' => $autor, 'hash_sha256' => hash('sha256', $contenido)]);
            throw $error;
        }
        $paginas = ArchivoPdf::paginas($pdf);
        $ruta = 'operaciones/archivo/'.Str::uuid().'.pdf';
        $disco = (string) config('legajo.disco_documentos');
        abort_unless(Storage::disk($disco)->put($ruta, AlmacenDocumento::cifrar($contenido)), 503, 'No se pudo proteger el inventario.');
        try {
            return ArchivoDocumento::query()->create(['almacenamiento' => $disco, 'storage_path' => $ruta,
                'nombre_original' => mb_substr($pdf->getClientOriginalName(), 0, 255), 'mime_type' => 'application/pdf', 'tamano_bytes' => strlen($contenido),
                'hash_sha256' => ArchivoPdf::hash($pdf), 'num_paginas' => $paginas, 'cifrado' => true, 'estado_antivirus' => 'limpio', 'subido_por' => $autor, 'subido_en' => now()]);
        } catch (Throwable $error) {
            Storage::disk($disco)->delete($ruta);
            throw $error;
        }
    }
}
