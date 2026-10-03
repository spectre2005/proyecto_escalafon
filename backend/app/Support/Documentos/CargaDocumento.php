<?php

namespace App\Support\Documentos;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\HistorialEstadoDocumento;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Carga de un documento al legajo, común a todas las secciones (Directiva, arts. 2.3 y 5.3: nada se
 * elimina ni se reemplaza). Guarda el PDF en el disco privado con nombre aleatorio y, en una transacción:
 * reserva los folios, crea archivos_documento, documentos_usuario (estado "cargado") y el primer registro
 * de historial_estados_documento, y enlaza el registro de datos si se indicó.
 */
final class CargaDocumento
{
    public const ESTADO_INICIAL = 'cargado';

    /**
     * @param  array<string, mixed>  $metadatos  ya validados (folios, fechas, emisor, titular, etc.)
     */
    public static function registrar(Legajo $legajo, TipoDocumento $tipo, UploadedFile $pdf, array $metadatos, string $autor, ?string $ip, ?string $subsanaA = null, ?string $enlaceId = null): DocumentoUsuario
    {
        $disco = (string) config('legajo.disco_documentos');
        $ruta = sprintf('legajos/%s/seccion-%02d/%s.pdf', $legajo->id, $tipo->seccion_legajo_id, Str::uuid());
        $archivo = [
            'almacenamiento' => $disco, 'storage_path' => $ruta, 'nombre_original' => mb_substr($pdf->getClientOriginalName(), 0, 255),
            'mime_type' => 'application/pdf', 'tamano_bytes' => $pdf->getSize(), 'hash_sha256' => ArchivoPdf::hash($pdf),
            'num_paginas' => ArchivoPdf::paginas($pdf),
            // TODO pendiente de confirmar con el cliente: cifrado en reposo. Hoy el archivo no se cifra.
            'cifrado' => false,
            // TODO: sin antivirus integrado todavía; queda pendiente de revisión.
            'estado_antivirus' => 'pendiente',
            'subido_por' => $autor,
        ];
        Storage::disk($disco)->putFileAs(dirname($ruta), $pdf, basename($ruta));

        try {
            return DB::transaction(function () use ($legajo, $tipo, $metadatos, $autor, $ip, $subsanaA, $enlaceId, $archivo): DocumentoUsuario {
                Folios::reservar($legajo, $tipo->seccion_legajo_id, $metadatos['folio_inicio'], $metadatos['folio_fin']);
                self::validarSubsanacion($legajo, $tipo, $subsanaA);
                $registroArchivo = ArchivoDocumento::query()->create([...$archivo, 'subido_en' => now()]);
                $documento = DocumentoUsuario::query()->create([...$metadatos,
                    'usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id, 'tipo_documento_id' => $tipo->id,
                    'seccion_legajo_id' => $tipo->seccion_legajo_id, 'archivo_id' => $registroArchivo->id,
                    'estado' => self::ESTADO_INICIAL, 'subsana_a_documento_id' => $subsanaA, 'created_by' => $autor,
                ]);
                HistorialEstadoDocumento::query()->create([
                    'documento_usuario_id' => $documento->id, 'estado_anterior' => null, 'estado_nuevo' => self::ESTADO_INICIAL,
                    'cambiado_por' => $autor, 'ip_origen' => $ip, 'fecha_cambio' => now(),
                    'motivo' => $subsanaA ? 'Carga que subsana al documento '.$subsanaA.'.' : 'Carga inicial.',
                ]);
                if ($enlaceId !== null) {
                    FormulariosDatos::enlazarRegistro($documento, (string) $tipo->formulario_datos, $enlaceId);
                }

                return $documento->refresh();
            });
        } catch (Throwable $exception) {
            // El registro no se creó: el archivo huérfano se retira para no dejar PDF sin metadatos.
            Storage::disk($disco)->delete($ruta);
            throw $exception;
        }
    }

    /**
     * Un documento corregido no se toca: el nuevo lo subsana. El subsanado debe ser del mismo legajo y tipo y no
     * estar ya subsanado. Si el tipo no admite varios documentos, uno nuevo solo entra subsanando al vigente.
     */
    private static function validarSubsanacion(Legajo $legajo, TipoDocumento $tipo, ?string $subsanaA): void
    {
        $vigentes = DocumentoUsuario::query()->where('legajo_id', $legajo->id)->where('tipo_documento_id', $tipo->id)
            ->whereDoesntHave('documentosUsuarioPorSubsanaADocumento')->lockForUpdate()->pluck('id');
        if ($subsanaA !== null && ! $vigentes->contains($subsanaA)) {
            throw ValidationException::withMessages(['subsana_a_documento_id' => 'El documento a subsanar debe ser de este legajo y tipo, y no estar ya subsanado.']);
        }
        if ($subsanaA === null && ! $tipo->permite_multiples && $vigentes->isNotEmpty()) {
            throw ValidationException::withMessages(['subsana_a_documento_id' => 'Este tipo admite un solo documento y ya tiene uno. Para corregirlo, sube uno nuevo que subsane al anterior.']);
        }
    }
}
