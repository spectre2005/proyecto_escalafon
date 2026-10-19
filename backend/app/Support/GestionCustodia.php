<?php

namespace App\Support;

use App\Models\Legajo;
use App\Models\MovimientoCustodiaLegajo;
use App\Models\SolicitudAccesoLegajo;
use App\Support\Documentos\DocumentosOperativos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class GestionCustodia
{
    public static function solicitar(Request $request, array $datos): SolicitudAccesoLegajo
    {
        return DB::transaction(function () use ($request, $datos): SolicitudAccesoLegajo {
            $legajo = Legajo::query()->lockForUpdate()->findOrFail($datos['legajo_id']);
            $documentos = DocumentosOperativos::consulta($request, $legajo->id, soloActivos: false)->whereIn('id', $datos['documentos_autorizados'])->with('tipoDocumento')->get();
            abort_unless($documentos->count() === count($datos['documentos_autorizados']), 422, 'Selecciona documentos disponibles del mismo legajo.');
            $sensible = $documentos->contains(fn ($d): bool => in_array($d->seccion_legajo_id, [1, 9, 10], true) || $d->tipoDocumento->es_dato_sensible || $d->tipoDocumento->es_dato_salud);
            abort_if($sensible && $datos['tipo_solicitante'] !== 'titular', 422, 'El acceso solicitado por terceros debe excluir los documentos sensibles y de salud.');
            if ($datos['tipo_solicitante'] === 'titular') {
                $titular = $legajo->usuario;
                abort_unless($datos['solicitante_documento'] === $titular->numero_doc_identidad, 422, 'El documento del solicitante debe corresponder al titular del legajo.');
                $datos['solicitante_usuario_id'] = $titular->id;
                $datos['solicitante_nombre'] = trim($titular->nombres.' '.$titular->apellido_paterno.' '.$titular->apellido_materno);
            }
            abort_if($sensible && ! $datos['incluye_datos_sensibles'], 422, 'El alcance contiene datos sensibles. Decláralo expresamente en la solicitud.');
            $solicitud = new SolicitudAccesoLegajo;
            $solicitud->forceFill([...$datos, 'estado' => 'pendiente'])->save();
            AuditoriaInstitucional::registrar('solicitudes_acceso_legajo', $solicitud->id, 'custodia.solicitar', $request, $legajo->usuario_id, null, $solicitud->getAttributes());

            return $solicitud->refresh();
        });
    }

    public static function resolver(Request $request, string $id, array $datos): SolicitudAccesoLegajo
    {
        return DB::transaction(function () use ($request, $id, $datos): SolicitudAccesoLegajo {
            $solicitud = SolicitudAccesoLegajo::query()->lockForUpdate()->findOrFail($id);
            abort_unless($solicitud->version === (int) $datos['version'], 409, 'La solicitud cambió. Actualiza la consulta.');
            $antes = $solicitud->getAttributes();
            if ($datos['accion'] === 'revocar') {
                abort_unless($solicitud->estado === 'aprobada', 409, 'Solo se revoca una autorización aprobada.');
                $estado = 'revocada';
            } else {
                abort_unless($solicitud->estado === 'pendiente', 409, 'La solicitud ya está resuelta.');
                $estado = $datos['accion'] === 'aprobar' ? 'aprobada' : 'rechazada';
                if ($estado === 'aprobada') {
                    abort_unless($solicitud->compromiso_confidencialidad && count($solicitud->documentos_autorizados ?? []) > 0, 422, 'Falta el compromiso de confidencialidad o un alcance documental explícito.');
                    abort_if($solicitud->incluye_datos_sensibles && ! $request->user()->veDatosSensibles(), 403);
                    self::exigirAlcance($request, $solicitud);
                    $solicitud->vigente_hasta = $datos['vigente_hasta'];
                }
            }
            $solicitud->forceFill(['estado' => $estado, 'resuelta_por' => $request->user()->id, 'fecha_resolucion' => now(), 'motivo_resolucion' => $datos['motivo'], 'version' => $solicitud->version + 1])->save();
            AuditoriaInstitucional::registrar('solicitudes_acceso_legajo', $solicitud->id, 'custodia.'.$datos['accion'], $request, $solicitud->legajo->usuario_id, $antes, $solicitud->getAttributes());

            return $solicitud->refresh();
        });
    }

    public static function prestar(Request $request, string $id, array $datos): MovimientoCustodiaLegajo
    {
        return DB::transaction(function () use ($request, $id, $datos): MovimientoCustodiaLegajo {
            $solicitud = SolicitudAccesoLegajo::query()->findOrFail($id);
            Legajo::query()->whereKey($solicitud->legajo_id)->lockForUpdate()->firstOrFail();
            $solicitud = SolicitudAccesoLegajo::query()->lockForUpdate()->findOrFail($id);
            self::exigirVigencia($request, $solicitud);
            abort_unless($datos['fecha_devolucion_prevista'] <= $solicitud->vigente_hasta->toDateString(), 422, 'La devolución prevista debe estar dentro de la autorización.');
            abort_unless($solicitud->forma_entrega === 'prestamo', 409, 'La autorización no es de préstamo.');
            abort_unless($datos['fecha_movimiento'] >= $solicitud->fecha_resolucion->setTimezone('America/Lima')->toDateString(), 422, 'La entrega debe ser posterior a la autorización.');
            abort_if(MovimientoCustodiaLegajo::query()->where('legajo_id', $solicitud->legajo_id)->where('tipo_movimiento', 'prestamo')->whereNull('fecha_retorno')->whereNull('devuelto_en')->exists(), 409, 'Ya hay un préstamo pendiente de devolución para este legajo.');
            $movimiento = new MovimientoCustodiaLegajo;
            $movimiento->forceFill([...$datos, 'legajo_id' => $solicitud->legajo_id, 'solicitud_acceso_id' => $solicitud->id, 'tipo_movimiento' => 'prestamo', 'base_autorizacion' => 'solicitud_aprobada', 'autorizado_por' => $request->user()->id])->save();
            AuditoriaInstitucional::registrar('movimientos_custodia_legajo', $movimiento->id, 'custodia.prestar', $request, $solicitud->legajo->usuario_id, null, $movimiento->getAttributes());

            return $movimiento->refresh();
        });
    }

    public static function devolver(Request $request, string $id, array $datos): MovimientoCustodiaLegajo
    {
        return DB::transaction(function () use ($request, $id, $datos): MovimientoCustodiaLegajo {
            $movimiento = MovimientoCustodiaLegajo::query()->lockForUpdate()->findOrFail($id);
            abort_unless($movimiento->version === (int) $datos['version'], 409, 'El préstamo cambió. Actualiza la consulta.');
            abort_unless($movimiento->tipo_movimiento === 'prestamo' && $movimiento->fecha_retorno === null && $movimiento->devuelto_en === null, 409, 'El préstamo ya fue devuelto.');
            abort_unless($datos['fecha_retorno'] >= $movimiento->fecha_movimiento->toDateString(), 422, 'La devolución no puede preceder a la entrega.');
            $antes = $movimiento->getAttributes();
            $movimiento->forceFill(['fecha_retorno' => $datos['fecha_retorno'], 'devuelto_en' => now(), 'recibido_por' => $request->user()->id, 'version' => $movimiento->version + 1])->save();
            AuditoriaInstitucional::registrar('movimientos_custodia_legajo', $movimiento->id, 'custodia.devolver', $request, $movimiento->legajo->usuario_id, $antes, [...$movimiento->getAttributes(), 'motivo' => $datos['motivo']]);

            return $movimiento->refresh();
        });
    }

    public static function exigirVigencia(Request $request, SolicitudAccesoLegajo $solicitud): void
    {
        abort_unless($solicitud->estado === 'aprobada' && $solicitud->vigente_hasta && $solicitud->vigente_hasta->toDateString() >= now('America/Lima')->toDateString(), 403, 'La autorización no está vigente o fue revocada.');
        abort_if($solicitud->incluye_datos_sensibles && ! $request->user()->veDatosSensibles(), 403);
        self::exigirAlcance($request, $solicitud);
    }

    private static function exigirAlcance(Request $request, SolicitudAccesoLegajo $solicitud): void
    {
        $documentos = DocumentosOperativos::consulta($request, $solicitud->legajo_id, soloActivos: false)
            ->whereIn('id', $solicitud->documentos_autorizados ?? [])->with('tipoDocumento')->get();
        abort_unless($documentos->isNotEmpty() && $documentos->count() === count($solicitud->documentos_autorizados ?? []), 422, 'El alcance contiene documentos que ya no están disponibles. Registra una nueva solicitud.');
        $sensible = $documentos->contains(fn ($d): bool => in_array($d->seccion_legajo_id, [1, 9, 10], true) || $d->tipoDocumento->es_dato_sensible || $d->tipoDocumento->es_dato_salud);
        abort_if($sensible && (! $solicitud->incluye_datos_sensibles || $solicitud->tipo_solicitante !== 'titular'
            || $solicitud->solicitante_usuario_id !== $solicitud->legajo->usuario_id
            || $solicitud->solicitante_documento !== $solicitud->legajo->usuario->numero_doc_identidad), 403, 'El alcance actual contiene datos sensibles que esta autorización no permite entregar.');
    }
}
