<?php

namespace App\Support\Documentos;

use App\Models\DocumentoUsuario;
use App\Models\HistorialEstadoDocumento;
use App\Models\Legajo;
use App\Models\ObservacionDocumento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class RevisionDocumento
{
    /** Administración institucional puede revisar su carga; las rutas siguen exigiendo permiso de revisión. */
    public static function permiteRevisarCarga(Request $request, DocumentoUsuario $documento): bool
    {
        return $documento->created_by !== $request->user()->getAuthIdentifier()
            || $request->user()->rolesVigentes()->contains('codigo', 'ADMIN');
    }

    public static function cambiarEstado(Request $request, string $usuario, string $documento, int $seccion, string $accion): JsonResponse
    {
        $hoy = in_array($seccion, [4, 6, 8, 10], true) ? now('America/Lima')->startOfDay() : today();
        $reglas = [
            'estado' => in_array($seccion, [4, 6, 8, 10], true) ? 'required|in:aceptado,observado,anulado' : 'required|in:aceptado,observado', 'estado_anterior' => 'required|string|max:20',
            'descripcion' => in_array($seccion, [4, 6, 8, 10], true) ? 'required_if:estado,observado,anulado|nullable|string|max:3000' : 'required_if:estado,observado|nullable|string|max:3000',
            'plazo_subsanacion' => 'required_if:estado,observado|nullable|date_format:Y-m-d|after_or_equal:'.$hoy->toDateString(),
            'recepcion_confirmada' => 'required_if:estado,aceptado|boolean',
            'fedateado' => 'sometimes|boolean',
        ];
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }
        $datos = $request->validate($reglas);
        if ($datos['estado'] === 'anulado') {
            abort_unless($request->user()->can('sistema.administrar'), 403, 'Solo administración puede anular documentos.');
        }
        if (in_array($seccion, [4, 6, 8, 10], true) && isset($datos['descripcion']) && trim($datos['descripcion']) === '') {
            throw ValidationException::withMessages(['descripcion' => 'Explica el motivo antes de guardar.']);
        }
        $registro = DB::transaction(function () use ($request, $usuario, $documento, $datos, $seccion, $accion, $hoy): DocumentoUsuario {
            $legajo = Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->lockForUpdate()->first();
            abort_unless($legajo, 409, 'El legajo está cerrado y no admite revisiones.');
            $doc = DocumentoUsuario::query()->where('usuario_id', $usuario)->where('legajo_id', $legajo->id)->where('seccion_legajo_id', $seccion)->with(['tipoDocumento', 'archivo'])
                ->lockForUpdate()->findOrFail($documento);
            abort_if(($doc->tipoDocumento->es_dato_sensible || $doc->tipoDocumento->es_dato_salud) && ! $request->user()->veDatosSensibles(), 403, 'Tu perfil no tiene acceso a este documento sensible.');
            abort_unless($doc->legajo->estado_legajo === 'activo', 409, 'El legajo está cerrado y no admite revisiones.');
            abort_unless(self::permiteRevisarCarga($request, $doc), 403, 'La revisión debe hacerla otra persona de RR. HH. No puedes revisar tu propia carga.');
            abort_if($doc->documentosUsuarioPorSubsanaADocumento()->exists() || $doc->estado === 'anulado', 409, 'El documento fue subsanado o anulado. Revisa el documento vigente.');
            abort_if($doc->estado !== $datos['estado_anterior'] || $doc->estado === $datos['estado'], 409, 'El documento cambió desde que lo abriste. Actualiza la lista antes de revisarlo.');
            $antes = $doc->getAttributes();
            $cambios = ['estado' => $datos['estado']];
            $observacion = null;
            if ($datos['estado'] === 'aceptado') {
                if ($seccion === 4 && $doc->tipoDocumento->codigo === 'EXP_CERTIFICADO_TRABAJO') {
                    $periodos = $doc->experienciaLaboral()->get();
                    if ($periodos->isEmpty() || $periodos->contains(fn ($p): bool => $p->usuario_id !== $doc->usuario_id || $p->anulado
                        || $p->es_ejercicio_profesional === null || $p->es_docencia_universitaria === null
                        || ! in_array($p->sector, ['publico', 'privado'], true)
                        || $p->fecha_inicio->format('Y-m-d') > $hoy->toDateString()
                        || ($p->fecha_fin && ($p->fecha_fin->lt($p->fecha_inicio) || $p->fecha_fin->format('Y-m-d') > $hoy->toDateString())))) {
                        throw ValidationException::withMessages(['estado' => 'El certificado tiene datos de experiencia incompletos o inválidos. Registra una subsanación con el periodo y su clasificación.']);
                    }
                }
                if ($seccion === 6) {
                    self::validarDatosCompensaciones($doc, $hoy->toDateString());
                }
                if (! ($datos['recepcion_confirmada'] ?? false)) {
                    throw ValidationException::withMessages(['recepcion_confirmada' => 'Confirma que verificaste la recepción, el sello y los folios.']);
                }
                if (! $doc->archivo || $doc->archivo->estado_antivirus === 'infectado') {
                    throw ValidationException::withMessages(['estado' => 'El documento no tiene un archivo disponible o el archivo está bloqueado. Registra una subsanación.']);
                }
                if (($doc->archivo->almacenamiento !== config('legajo.disco_documentos')
                    || ! Storage::disk(config('legajo.disco_documentos'))->exists($doc->archivo->storage_path))) {
                    throw ValidationException::withMessages(['estado' => 'El archivo PDF no está disponible. Registra una subsanación.']);
                }
                AlmacenDocumento::leer($doc->archivo);
                if ($doc->tipoDocumento->requiere_fedateo || $doc->fedateado || ($datos['fedateado'] ?? false)) {
                    GestionFedateo::validarRegistro($request, $doc);
                }
                if (! $doc->fecha_recepcion) {
                    $cambios += ['fecha_recepcion' => $hoy, 'recepcionado_por' => $request->user()->getAuthIdentifier()];
                }
                abort_if($doc->observacionesDocumentoPorDocumento()->where('estado', 'abierta')->exists(), 409, 'Tiene una observación abierta. Registra un documento nuevo que la subsane antes de aceptarlo.');
            } elseif ($datos['estado'] === 'observado') {
                $observacion = ObservacionDocumento::query()->create([
                    'documento_id' => $doc->id, 'tipo_observacion' => 'otro', 'descripcion' => trim($datos['descripcion']),
                    'plazo_subsanacion' => $datos['plazo_subsanacion'], 'registrada_por' => $request->user()->getAuthIdentifier(), 'fecha_registro' => now(),
                ]);
            }
            $doc->update($cambios);
            if ($doc->estado === 'aceptado' && $doc->subsana_a_documento_id) {
                ObservacionDocumento::query()->where('documento_id', $doc->subsana_a_documento_id)->where('estado', 'abierta')->update([
                    'estado' => 'subsanada', 'documento_subsanacion_id' => $doc->id,
                    'fecha_subsanacion' => now(), 'verificada_por' => $request->user()->getAuthIdentifier(),
                    'comentario_cierre' => 'Subsanación aceptada por RR. HH.',
                ]);
            }
            HistorialEstadoDocumento::query()->create([
                'documento_usuario_id' => $doc->id, 'estado_anterior' => $antes['estado'], 'estado_nuevo' => $doc->estado,
                'cambiado_por' => $request->user()->getAuthIdentifier(), 'fecha_cambio' => now(), 'ip_origen' => $request->ip(),
                'observacion_id' => $observacion?->id, 'motivo' => $observacion?->descripcion ?? ($datos['descripcion'] ?? null) ?? 'Revisión y recepción conforme de RR. HH.; fiscalización posterior independiente.',
            ]);
            AuditoriaDocumento::registrar($doc, $request, $accion.'.'.$doc->estado, $antes);

            return $doc;
        });

        return response()->json(['data' => $registro->only(['id', 'estado', 'fedateado', 'fiscalizado'])]);
    }

    private static function validarDatosCompensaciones(DocumentoUsuario $doc, string $hoy): void
    {
        if ($doc->tipoDocumento->codigo === 'COMP_RECONOCIMIENTO_TIEMPO') {
            $filas = $doc->tiempoServiciosReconocidos()->get();
            $valido = $filas->count() === 1 && ! $filas->contains(fn ($p): bool => $p->usuario_id !== $doc->usuario_id || $p->anulado
                || $p->anios < 0 || $p->meses < 0 || $p->dias < 0 || $p->numero_resolucion !== $doc->num_resolucion
                || $p->fecha_resolucion->format('Y-m-d') !== $doc->fecha_emision?->format('Y-m-d') || $p->fecha_resolucion->format('Y-m-d') > $hoy);
        } elseif ($doc->tipoDocumento->codigo === 'COMP_PAGO_COMPENSACION') {
            $filas = $doc->compensacionesBeneficios()->get();
            $valido = $filas->count() === 1 && ! $filas->contains(fn ($p): bool => $p->usuario_id !== $doc->usuario_id || $p->anulado
                || ! in_array($p->tipo, ['economica', 'no_economica'], true) || trim($p->concepto) === '' || $p->fecha->format('Y-m-d') > $hoy);
        } else {
            return;
        }
        if (! $valido) {
            throw ValidationException::withMessages(['estado' => 'Los datos estructurados no corresponden al documento o están incompletos. Registra una subsanación.']);
        }
    }
}
