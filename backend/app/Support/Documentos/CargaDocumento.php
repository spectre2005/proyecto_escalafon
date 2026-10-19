<?php

namespace App\Support\Documentos;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\HistorialEstadoDocumento;
use App\Models\Legajo;
use App\Models\ObservacionDocumento;
use App\Models\TipoDocumento;
use App\Support\AplicacionDocumental;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Carga de un documento al legajo, común a todas las secciones (Directiva, arts. 2.3 y 5.3: nada se
 * elimina ni se reemplaza). Guarda el PDF en el disco privado con nombre aleatorio y, en una transacción:
 * reserva los folios, crea archivos_documento, documentos_usuario (estado "aceptado") y el primer registro
 * de historial_estados_documento, y enlaza el registro de datos si se indicó.
 */
final class CargaDocumento
{
    public const ESTADO_INICIAL = 'aceptado';

    /**
     * @param  array<string, mixed>  $metadatos  ya validados (folios, fechas, emisor, titular, etc.)
     */
    public static function registrar(Legajo $legajo, TipoDocumento $tipo, UploadedFile $pdf, array $metadatos, string $autor, ?string $ip, ?string $subsanaA = null, ?string $enlaceId = null, string $estadoInicial = self::ESTADO_INICIAL, ?Closure $completar = null, bool $foliosAutomaticos = false): DocumentoUsuario
    {
        // Solo se cargan los tipos que corresponden a la persona (AplicacionDocumental). Subsanar un documento ya
        // existente se permite siempre: corrige algo que ya está en el legajo.
        $noAplica = $subsanaA === null ? AplicacionDocumental::para($legajo->usuario()->firstOrFail())->noAplica($tipo) : null;
        if ($noAplica !== null) {
            throw ValidationException::withMessages(['tipo_documento_id' => "Este tipo de documento no corresponde a la persona. {$noAplica}"]);
        }
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
        if ($foliosAutomaticos && (! $archivo['num_paginas'] || $archivo['num_paginas'] < 1)) {
            throw ValidationException::withMessages(['pdf' => 'No se pudo contar las páginas del PDF para asignar sus folios. Guarda una copia del PDF e intenta nuevamente.']);
        }
        if (! Storage::disk($disco)->putFileAs(dirname($ruta), $pdf, basename($ruta))) {
            throw new RuntimeException('No se pudo almacenar el PDF. Intenta nuevamente.');
        }

        try {
            return DB::transaction(function () use ($legajo, $tipo, $metadatos, $autor, $ip, $subsanaA, $enlaceId, $archivo, $estadoInicial, $completar, $foliosAutomaticos): DocumentoUsuario {
                $actual = Legajo::query()->whereKey($legajo->id)->lockForUpdate()->firstOrFail();
                abort_unless($actual->estado_legajo === 'activo', 409, 'El legajo está cerrado. Solo se permite visualizar y descargar.');
                if ($foliosAutomaticos) {
                    Legajo::query()->whereKey($legajo->id)->lockForUpdate()->first();
                    $metadatos['folio_inicio'] = Folios::ultimo($legajo, $tipo->seccion_legajo_id) + 1;
                    $metadatos['folio_fin'] = $metadatos['folio_inicio'] + $archivo['num_paginas'] - 1;
                    $subsanaA = DocumentoUsuario::query()->where('legajo_id', $legajo->id)->where('tipo_documento_id', $tipo->id)
                        ->whereDoesntHave('documentosUsuarioPorSubsanaADocumento')->orderByDesc('folio_fin')->value('id');
                }
                Folios::reservar($legajo, $tipo->seccion_legajo_id, $metadatos['folio_inicio'], $metadatos['folio_fin']);
                self::validarSubsanacion($legajo, $tipo, $subsanaA);
                $registroArchivo = ArchivoDocumento::query()->create([...$archivo, 'subido_en' => now()]);
                $documento = DocumentoUsuario::query()->create([...$metadatos,
                    'usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id, 'tipo_documento_id' => $tipo->id,
                    'seccion_legajo_id' => $tipo->seccion_legajo_id, 'archivo_id' => $registroArchivo->id,
                    'estado' => $estadoInicial, 'subsana_a_documento_id' => $subsanaA, 'created_by' => $autor,
                ]);
                HistorialEstadoDocumento::query()->create([
                    'documento_usuario_id' => $documento->id, 'estado_anterior' => null, 'estado_nuevo' => $estadoInicial,
                    'cambiado_por' => $autor, 'ip_origen' => $ip, 'fecha_cambio' => now(),
                    'motivo' => $subsanaA ? 'Carga que subsana al documento '.$subsanaA.'.' : 'Carga inicial aceptada directamente por el responsable del registro.',
                ]);
                if ($estadoInicial === 'aceptado' && $subsanaA !== null) {
                    ObservacionDocumento::query()->where('documento_id', $subsanaA)->where('estado', 'abierta')->update([
                        'estado' => 'subsanada', 'documento_subsanacion_id' => $documento->id,
                        'fecha_subsanacion' => now(), 'verificada_por' => $autor,
                        'comentario_cierre' => 'Subsanación aceptada directamente por el responsable del registro.',
                    ]);
                }
                if ($enlaceId !== null) {
                    FormulariosDatos::enlazarRegistro($documento, (string) $tipo->formulario_datos, $enlaceId);
                }
                if ($completar !== null) {
                    $completar($documento);
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
            ->when(in_array($tipo->seccion_legajo_id, [3, 4, 6, 10], true), fn ($query) => $query->where('estado', '!=', 'anulado'))
            ->whereDoesntHave('documentosUsuarioPorSubsanaADocumento')->lockForUpdate()->pluck('id');
        if ($subsanaA !== null && ! $vigentes->contains($subsanaA)) {
            throw ValidationException::withMessages(['subsana_a_documento_id' => 'El documento a subsanar debe ser de este legajo y tipo, y no estar ya subsanado.']);
        }
        if ($subsanaA === null && ! $tipo->permite_multiples && $vigentes->isNotEmpty()) {
            throw ValidationException::withMessages(['subsana_a_documento_id' => 'Este tipo admite un solo documento y ya tiene uno. Para corregirlo, sube uno nuevo que subsane al anterior.']);
        }
    }
}
