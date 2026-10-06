<?php

namespace App\Support\Documentos;

use App\Models\DocumentoUsuario;
use App\Models\HistorialEstadoDocumento;
use App\Models\Legajo;
use App\Models\RegistroSstBienestar;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegistroSeccion10
{
    public const CAMPOS_REGISTRO = ['descripcion', 'numero_documento', 'fecha_inicio', 'fecha_fin', 'fecha_vencimiento', 'entidad'];

    public static function crear(Request $request, Usuario $persona, TipoDocumento $tipo, array $datos): DocumentoUsuario
    {
        return DB::transaction(function () use ($request, $persona, $tipo, $datos): DocumentoUsuario {
            $legajo = self::legajoAbierto($persona->id);
            $autor = $request->user()->getAuthIdentifier();
            $meta = self::metadatos($persona, $datos);
            $subsana = $datos['subsana_a_documento_id'] ?? null;
            $anterior = null;
            if ($subsana) {
                $anterior = DocumentoUsuario::query()->where('usuario_id', $persona->id)->where('legajo_id', $legajo->id)
                    ->where('seccion_legajo_id', 10)->where('tipo_documento_id', $tipo->id)->where('estado', '!=', 'anulado')
                    ->whereDoesntHave('documentosUsuarioPorSubsanaADocumento')->lockForUpdate()->find($subsana);
                if (! $anterior || ($datos['pendiente'] ?? false)) {
                    throw ValidationException::withMessages(['subsana_a_documento_id' => 'Adjunta un PDF para completar o subsanar un documento vigente de esta persona y tipo.']);
                }
            }
            $completar = function (DocumentoUsuario $doc) use ($request, $datos, $tipo, $anterior): void {
                $registro = RegistroSstBienestar::query()->create([
                    ...collect($datos)->only(self::CAMPOS_REGISTRO)->all(), 'usuario_id' => $doc->usuario_id,
                    'documento_id' => $doc->id, 'tipo' => $tipo->codigo, 'fecha' => $doc->fecha_emision,
                    'estado_tramite' => $doc->archivo_id ? 'concluido' : 'en_tramite', 'registrado_por' => $doc->created_by,
                ]);
                $despues = self::datosAuditoria($doc, $registro);
                AuditoriaDocumento::registrar($doc, $request, 'CREAR_DOCUMENTO', despues: $despues);
                if ($doc->archivo_id) {
                    AuditoriaDocumento::registrar($doc, $request, 'ADJUNTAR_ARCHIVO', despues: $despues);
                }
                if ($anterior) {
                    AuditoriaDocumento::registrar($doc, $request, $anterior->archivo_id ? 'REEMPLAZAR_ARCHIVO' : 'COMPLETAR_TRAMITE',
                        ['documento_anterior_id' => $anterior->id], $despues);
                    AuditoriaDocumento::registrar($anterior, $request, 'DOCUMENTO_SUBSANADO', despues: ['documento_nuevo_id' => $doc->id]);
                }
            };
            if ($datos['pendiente'] ?? false) {
                $doc = DocumentoUsuario::query()->create([...$meta, 'usuario_id' => $persona->id, 'legajo_id' => $legajo->id,
                    'seccion_legajo_id' => 10, 'tipo_documento_id' => $tipo->id, 'estado' => 'pendiente', 'created_by' => $autor]);
                self::historial($doc, $request, null, 'Trámite iniciado; falta adjuntar la evidencia documental.');
                $completar($doc);

                return $doc;
            }
            $inicio = Folios::ultimo($legajo, 10) + 1;
            $fin = $inicio + (int) $datos['numero_folios'] - 1;
            if ($fin > 2147483647) {
                throw ValidationException::withMessages(['numero_folios' => 'La cantidad supera el rango de folios permitido.']);
            }

            return CargaDocumento::registrar($legajo, $tipo, $request->file('pdf'), [...$meta, 'folio_inicio' => $inicio, 'folio_fin' => $fin],
                $autor, $request->ip(), $subsana, completar: $completar);
        });
    }

    public static function actualizar(Request $request, string $usuario, string $documento, array $datos): DocumentoUsuario
    {
        return DB::transaction(function () use ($request, $usuario, $documento, $datos): DocumentoUsuario {
            $legajo = self::legajoAbierto($usuario);
            $doc = self::documento($usuario, $documento, $legajo);
            $registro = self::registro($doc);
            abort_unless(self::editable($doc) && ! $registro->anulado, 409,
                'El documento ya fue recibido, validado, anulado o subsanado. Corrígelo con una nueva evidencia.');
            abort_if($doc->estado !== $datos['estado_anterior'], 409, 'El documento cambió. Actualiza la ficha.');
            $antes = self::datosAuditoria($doc, $registro);
            $doc->update(collect($datos)->only(['nombre', 'emisor', 'fecha_emision', 'modalidad_presentacion'])->all());
            $registro->update([...collect($datos)->only(self::CAMPOS_REGISTRO)->all(), 'fecha' => $doc->fecha_emision]);
            AuditoriaDocumento::registrar($doc, $request, 'ACTUALIZAR_DOCUMENTO', $antes, self::datosAuditoria($doc, $registro));

            return $doc;
        });
    }

    public static function anular(Request $request, string $usuario, string $documento, array $datos): DocumentoUsuario
    {
        return DB::transaction(function () use ($request, $usuario, $documento, $datos): DocumentoUsuario {
            $legajo = self::legajoAbierto($usuario);
            $doc = self::documento($usuario, $documento, $legajo);
            $registro = self::registro($doc);
            abort_if($doc->estado === 'anulado' || $doc->documentosUsuarioPorSubsanaADocumento()->exists(), 409,
                'El documento ya fue anulado o subsanado.');
            abort_if($doc->estado !== $datos['estado_anterior'], 409, 'El documento cambió. Actualiza la ficha.');
            $antes = self::datosAuditoria($doc, $registro);
            $doc->update(['estado' => 'anulado']);
            $registro->update(['anulado' => true, 'motivo_anulacion' => $datos['motivo']]);
            self::historial($doc, $request, $antes['documento']['estado'], $datos['motivo']);
            AuditoriaDocumento::registrar($doc, $request, 'ANULAR_DOCUMENTO', $antes, self::datosAuditoria($doc, $registro));

            return $doc;
        });
    }

    public static function editable(DocumentoUsuario $doc): bool
    {
        return in_array($doc->estado, ['pendiente', 'cargado'], true) && ! $doc->fecha_recepcion && ! $doc->fiscalizado
            && $doc->documentosUsuarioPorSubsanaADocumento->isEmpty();
    }

    private static function legajoAbierto(string $usuario): Legajo
    {
        $legajo = Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')
            ->orderByDesc('fecha_apertura')->orderByDesc('id')->lockForUpdate()->first();
        abort_unless($legajo, 409, 'Primero abre el legajo de esta persona.');

        return $legajo;
    }

    private static function documento(string $usuario, string $documento, Legajo $legajo): DocumentoUsuario
    {
        return DocumentoUsuario::query()->where('usuario_id', $usuario)->where('legajo_id', $legajo->id)->where('seccion_legajo_id', 10)
            ->with('documentosUsuarioPorSubsanaADocumento')->lockForUpdate()->findOrFail($documento);
    }

    private static function registro(DocumentoUsuario $doc): RegistroSstBienestar
    {
        return RegistroSstBienestar::query()->where('documento_id', $doc->id)->where('usuario_id', $doc->usuario_id)->lockForUpdate()->firstOrFail();
    }

    private static function metadatos(Usuario $persona, array $datos): array
    {
        return [...collect($datos)->only(['nombre', 'emisor', 'fecha_emision', 'modalidad_presentacion'])->all(),
            'fecha_incorporacion' => now('America/Lima')->toDateString(), 'relacion_titular' => 'servidor',
            'titular_nombre' => mb_substr(trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 0, 200),
            'titular_documento' => $persona->numero_doc_identidad];
    }

    private static function historial(DocumentoUsuario $doc, Request $request, ?string $anterior, string $motivo): void
    {
        HistorialEstadoDocumento::query()->create(['documento_usuario_id' => $doc->id, 'estado_anterior' => $anterior,
            'estado_nuevo' => $doc->estado, 'cambiado_por' => $request->user()->getAuthIdentifier(),
            'fecha_cambio' => now(), 'ip_origen' => $request->ip(), 'motivo' => $motivo]);
    }

    private static function datosAuditoria(DocumentoUsuario $doc, RegistroSstBienestar $registro): array
    {
        return ['documento' => $doc->getAttributes(), 'registro' => $registro->getAttributes()];
    }
}
