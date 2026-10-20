<?php

namespace App\Support;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\ProcesoFiscalizacion;
use App\Models\SolicitudFiscalizacion;
use App\Support\Documentos\AlmacenDocumento;
use App\Support\Documentos\AuditoriaDocumento;
use App\Support\Documentos\DocumentosOperativos;
use App\Support\Documentos\EstadoDocumento;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GestionFiscalizacion
{
    public static function crear(Request $request, array $datos): ProcesoFiscalizacion
    {
        return DB::transaction(function () use ($request, $datos): ProcesoFiscalizacion {
            $legajo = Legajo::query()->lockForUpdate()->findOrFail($datos['legajo_id']);
            abort_unless($legajo->estado_legajo === 'activo', 409, 'La fiscalización se inicia en un legajo activo.');
            if (! empty($datos['tipo_documento_id'])) {
                $disponible = DocumentosOperativos::consulta($request, $legajo->id, $legajo->usuario_id)
                    ->where('tipo_documento_id', $datos['tipo_documento_id'])->whereDoesntHave('documentosUsuarioPorSubsanaADocumento')
                    ->whereHas('tipoDocumento', fn ($q) => $q->where('activo', true))->exists();
                if (! $disponible) {
                    throw ValidationException::withMessages(['tipo_documento_id' => 'El legajo no tiene documentos disponibles de este tipo. Actualiza la selección.']);
                }
                $datos['tipo_muestra'] = 'Verificación documental';
            }
            $proceso = new ProcesoFiscalizacion;
            $proceso->forceFill([...$datos, 'usuario_id' => $legajo->usuario_id, 'responsable_id' => $request->user()->id, 'estado' => 'abierto'])->save();
            AuditoriaInstitucional::registrar('procesos_fiscalizacion', $proceso->id, 'fiscalizacion.abrir', $request, $legajo->usuario_id, null, $proceso->getAttributes());

            return $proceso->refresh();
        });
    }

    public static function ejecutar(Request $request, string $id, array $datos): ProcesoFiscalizacion
    {
        return DB::transaction(function () use ($request, $id, $datos): ProcesoFiscalizacion {
            $proceso = ProcesoFiscalizacion::query()->lockForUpdate()->findOrFail($id);
            abort_unless($proceso->version === (int) $datos['version'], 409, 'El caso cambió. Actualiza antes de continuar.');
            $antes = $proceso->getAttributes();
            $accion = $datos['accion'];
            if ($accion === 'reabrir') {
                abort_unless($proceso->estado === 'cerrado', 409, 'Solo se reabre una fiscalización cerrada.');
                $proceso->forceFill(['estado' => 'abierto', 'fecha_fin' => null]);
            } else {
                abort_unless($proceso->estado === 'abierto', 409, 'La fiscalización no está abierta.');
                if ($accion === 'comunicar') {
                    $documento = self::documento($request, $proceso, $datos['documento_id']);
                    if ($proceso->tipo_documento_id && ($documento->tipo_documento_id !== $proceso->tipo_documento_id || $documento->documentosUsuarioPorSubsanaADocumento()->exists())) {
                        throw ValidationException::withMessages(['documento_id' => 'Selecciona un documento vigente del tipo elegido al abrir este caso.']);
                    }
                    if ($datos['oficio_documento_id'] === $documento->id) {
                        throw ValidationException::withMessages(['oficio_documento_id' => 'Selecciona el PDF del oficio de consulta, distinto del documento que se fiscaliza.']);
                    }
                    $oficio = self::documento($request, $proceso, $datos['oficio_documento_id']);
                    AlmacenDocumento::leer($oficio->archivo);
                    abort_unless($datos['fecha_envio'] >= $proceso->fecha_inicio->toDateString(), 422, 'La comunicación no puede preceder a la apertura.');
                    $solicitud = SolicitudFiscalizacion::query()->create([
                        'proceso_fiscalizacion_id' => $proceso->id, 'documento_id' => $documento->id,
                        'entidad_consultada' => $datos['entidad_consultada'], 'medio' => $datos['medio'], 'numero_oficio' => $datos['numero_oficio'],
                        'fecha_envio' => $datos['fecha_envio'], 'oficio_archivo_id' => $oficio->archivo_id, 'registrado_por' => $request->user()->id,
                    ]);
                    AuditoriaInstitucional::registrar('solicitudes_fiscalizacion', $solicitud->id, 'fiscalizacion.comunicar', $request, $proceso->usuario_id, null, $solicitud->getAttributes());
                } elseif ($accion === 'responder') {
                    $solicitud = SolicitudFiscalizacion::query()->where('proceso_fiscalizacion_id', $proceso->id)->lockForUpdate()->findOrFail($datos['solicitud_id']);
                    abort_unless($solicitud->resultado === 'pendiente', 409, 'La comunicación ya tiene respuesta. Registra otra comunicación si hay nueva evidencia.');
                    abort_unless($datos['fecha_respuesta'] >= $solicitud->fecha_envio->toDateString(), 422, 'La respuesta no puede preceder al envío.');
                    $respuesta = self::documento($request, $proceso, $datos['documento_respuesta_id']);
                    AlmacenDocumento::leer($respuesta->archivo);
                    $previo = $solicitud->getAttributes();
                    $solicitud->update(['fecha_respuesta' => $datos['fecha_respuesta'], 'documento_respuesta_id' => $respuesta->id, 'resultado' => $datos['resultado'], 'descripcion_hallazgo' => $datos['descripcion_hallazgo']]);
                    AuditoriaInstitucional::registrar('solicitudes_fiscalizacion', $solicitud->id, 'fiscalizacion.responder', $request, $proceso->usuario_id, $previo, $solicitud->getAttributes());
                } elseif ($accion === 'cerrar') {
                    $solicitudes = $proceso->solicitudesFiscalizacion()->lockForUpdate()->get();
                    abort_if($solicitudes->isEmpty() || $solicitudes->contains('resultado', 'pendiente'), 409, 'Responde todas las comunicaciones antes de cerrar el caso.');
                    abort_unless($datos['fecha_fin'] >= $proceso->fecha_inicio->toDateString(), 422, 'El cierre no puede preceder a la apertura.');
                    $informe = self::documento($request, $proceso, $datos['informe_documento_id']);
                    AlmacenDocumento::leer($informe->archivo);
                    foreach ($solicitudes->groupBy('documento_id') as $documentoId => $respuestas) {
                        $documento = self::documento($request, $proceso, $documentoId);
                        abort_if($documento->created_by === $request->user()->id, 403, 'Otra persona de administración debe fiscalizar los documentos que cargaste.');
                        $resultado = self::resultado($respuestas);
                        abort_if($resultado === 'conforme' && ! EstadoDocumento::recepcionado($documento), 422, 'Registra la recepción del documento antes de confirmar su conformidad.');
                        abort_if($resultado === 'conforme' && $documento->tipoDocumento->requiere_fedateo && ! $documento->fedateado, 422, 'Confirma el fedateo mediante la revisión documental antes de cerrar conforme.');
                        $previo = $documento->getAttributes();
                        $documento->update(['fiscalizado' => true, 'fecha_fiscalizacion' => now('America/Lima')->toDateString(), 'fiscalizado_por' => $request->user()->id, 'estado' => $resultado === 'conforme' ? 'verificado' : $resultado]);
                        AuditoriaDocumento::registrar($documento, $request, 'fiscalizacion.resultado', $previo, $documento->getAttributes());
                    }
                    $proceso->forceFill(['estado' => 'cerrado', 'resultado' => self::resultado($solicitudes), 'fecha_fin' => $datos['fecha_fin'], 'informe_documento_id' => $informe->id, 'conclusiones' => $datos['conclusiones']]);
                } elseif ($accion === 'prorrogar') {
                    abort_unless($datos['fecha_limite'] > $proceso->fecha_limite->toDateString(), 422, 'El nuevo plazo debe ampliar el plazo actual.');
                    $proceso->fecha_limite = $datos['fecha_limite'];
                } elseif ($accion === 'cancelar') {
                    $proceso->forceFill(['estado' => 'cancelado', 'fecha_fin' => now('America/Lima')->toDateString()]);
                }
            }
            $proceso->version++;
            $proceso->save();
            AuditoriaInstitucional::registrar('procesos_fiscalizacion', $proceso->id, 'fiscalizacion.'.$accion, $request, $proceso->usuario_id, $antes, [...$proceso->getAttributes(), 'motivo' => $datos['motivo'] ?? null]);

            return $proceso->refresh();
        });
    }

    private static function documento(Request $request, ProcesoFiscalizacion $proceso, string $id): DocumentoUsuario
    {
        return DocumentosOperativos::consulta($request, $proceso->legajo_id, $proceso->usuario_id)->with('archivo')->lockForUpdate()->findOrFail($id);
    }

    private static function resultado(Collection $respuestas): string
    {
        return $respuestas->contains('resultado', 'falso') ? 'falso' : ($respuestas->contains('resultado', 'observado') ? 'observado' : 'conforme');
    }
}
