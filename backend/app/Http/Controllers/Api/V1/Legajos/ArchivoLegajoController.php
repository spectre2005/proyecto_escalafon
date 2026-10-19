<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\BitacoraAccesoLegajo;
use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\MovimientoCustodiaLegajo;
use App\Models\ReferenciaLegajoPasivo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\AuditoriaInstitucional;
use App\Support\Documentos\AlmacenDocumento;
use App\Support\Documentos\CargaSoporteOperativo;
use App\Support\Documentos\DocumentosOperativos;
use App\Support\VigenciaLaboral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ArchivoLegajoController extends Controller
{
    public function index(Request $request, string $usuario): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $vinculos = VinculoLaboral::query()->where('usuario_id', $usuario)->where('anulado', false)->with('vinculoAdendas')->get()
            ->filter(fn ($v): bool => $v->fecha_inicio->format('Y-m-d') <= VigenciaLaboral::hoy() && ! VigenciaLaboral::vigente($v))
            ->map(fn ($v): array => [...$v->only(['id', 'nombre_puesto', 'regimen_laboral']), 'fecha_cese' => $v->fecha_cese?->format('Y-m-d'), 'fin_efectivo' => VigenciaLaboral::fin($v)?->format('Y-m-d')])->values();
        $referencias = ReferenciaLegajoPasivo::query()->whereHas('legajo', fn ($q) => $q->where('usuario_id', $usuario))
            ->whereIn('tipo_documento_id', DocumentosOperativos::tipos($request)->select('id'))
            ->with(['legajo:id,numero_legajo', 'legajoOrigen:id,numero_legajo,ubicacion', 'documentoOrigen:id,estado,archivo_id', 'tipoDocumento:id,nombre', 'responsableLegajo:id,nombres,apellido_paterno'])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20);
        $referencias->through(fn ($r): array => [...$r->only(['id', 'legajo_id', 'documento_origen_id', 'legajo_origen_id', 'vinculo_laboral_id', 'folio_inicio_pasivo', 'folio_fin_pasivo', 'fedateado', 'fecha_fedateo', 'fiscalizado', 'fecha_fiscalizacion', 'motivo', 'created_at']),
            'destino' => $r->legajo?->numero_legajo, 'origen' => $r->legajoOrigen, 'documento' => $r->tipoDocumento?->nombre,
            'estado_actual_fuente' => $r->documentoOrigen?->estado, 'responsable' => $r->responsableLegajo ? trim($r->responsableLegajo->nombres.' '.$r->responsableLegajo->apellido_paterno) : null]);
        $pasivos = Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'pasivo')->orderByDesc('fecha_pasivo')->get();
        $pasivos = $pasivos->map(fn ($l): array => [...$l->only(['id', 'numero_legajo', 'ubicacion', 'formato_legajo', 'fecha_apertura', 'fecha_pasivo', 'fecha_transferencia_archivo_central']),
            'transferible_desde' => $l->fecha_pasivo?->copy()->addYearsNoOverflow(3)->addDay()->format('Y-m-d'),
            'transferencia_id' => $l->movimientosCustodiaLegajo()->where('tipo_movimiento', 'transferencia')->orderByDesc('fecha_movimiento')->value('id')]);

        return response()->json(['data' => ['activo' => Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->first()?->only(['id', 'numero_legajo']),
            'pasivos' => $pasivos, 'vinculos_anteriores' => $vinculos, 'referencias' => $referencias, 'hoy' => VigenciaLaboral::hoy()]]);
    }

    public function documentos(Request $request, string $usuario, string $legajo): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1']);
        Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'pasivo')->findOrFail($legajo);

        return response()->json(DocumentosOperativos::consulta($request, $legajo, $usuario, soloActivos: false)->with('tipoDocumento:id,nombre')
            ->orderBy('seccion_legajo_id')->orderBy('folio_inicio')->orderBy('id')->paginate(20, ['id', 'tipo_documento_id', 'seccion_legajo_id', 'folio_inicio', 'folio_fin', 'estado', 'vinculo_laboral_id']));
    }

    public function referenciar(Request $request, string $usuario): JsonResponse
    {
        $datos = $this->validar($request, ['documento_origen_id' => 'required|uuid', 'vinculo_laboral_id' => 'required|uuid', 'motivo' => 'required|string|min:5|max:2000']);
        $referencia = DB::transaction(function () use ($request, $usuario, $datos): ReferenciaLegajoPasivo {
            Usuario::query()->whereKey($usuario)->lockForUpdate()->firstOrFail();
            $activo = Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->lockForUpdate()->first();
            abort_unless($activo, 409, 'Abre primero el legajo del reingreso.');
            $origen = DocumentosOperativos::consulta($request, null, $usuario, soloActivos: false)->whereHas('legajo', fn ($q) => $q->where('estado_legajo', 'pasivo'))->with(['legajo', 'archivo'])->findOrFail($datos['documento_origen_id']);
            $vinculo = VinculoLaboral::query()->where('usuario_id', $usuario)->where('anulado', false)->with('vinculoAdendas')->findOrFail($datos['vinculo_laboral_id']);
            abort_unless($vinculo->fecha_inicio->format('Y-m-d') <= VigenciaLaboral::hoy() && ! VigenciaLaboral::vigente($vinculo), 422, 'Selecciona el vínculo anterior terminado.');
            abort_if($origen->vinculo_laboral_id && $origen->vinculo_laboral_id !== $vinculo->id, 422, 'El vínculo debe corresponder al del documento de origen.');
            abort_unless($origen->folio_inicio >= 1 && $origen->folio_fin >= $origen->folio_inicio, 422, 'Los folios históricos necesitan revisión antes de referenciarse.');
            abort_if(ReferenciaLegajoPasivo::query()->where('legajo_id', $activo->id)->where('documento_origen_id', $origen->id)->exists(), 409, 'Este documento ya está referenciado en el legajo activo.');
            AlmacenDocumento::leer($origen->archivo);
            $r = new ReferenciaLegajoPasivo;
            $r->forceFill(['legajo_id' => $activo->id, 'legajo_origen_id' => $origen->legajo_id, 'documento_origen_id' => $origen->id,
                'vinculo_laboral_id' => $vinculo->id, 'tipo_documento_id' => $origen->tipo_documento_id,
                'folio_inicio_pasivo' => $origen->folio_inicio, 'folio_fin_pasivo' => $origen->folio_fin, 'fedateado' => $origen->fedateado, 'fecha_fedateo' => $origen->fecha_fedateo,
                'fiscalizado' => $origen->fiscalizado, 'fecha_fiscalizacion' => $origen->fecha_fiscalizacion, 'responsable_legajo_id' => $request->user()->id, 'motivo' => $datos['motivo']])->save();
            LegajoSeccion::query()->firstOrCreate(['legajo_id' => $activo->id, 'seccion_legajo_id' => 13], ['ultimo_folio' => 0]);
            AuditoriaInstitucional::registrar('referencias_legajo_pasivo', $r->id, 'archivo.referencia_registrada', $request, $usuario, null, $r->getAttributes());

            return $r;
        });

        return response()->json(['data' => $referencia], 201);
    }

    public function transferir(Request $request, string $legajo): JsonResponse
    {
        $datos = $this->validar($request, ['fecha_transferencia' => 'required|date_format:Y-m-d|before_or_equal:'.VigenciaLaboral::hoy(),
            'ubicacion' => 'required|string|max:200', 'entidad_destino' => 'required|string|max:200', 'numero_inventario' => 'required|string|max:40',
            'numero_documento_sustento' => 'required|string|max:60', 'referencia_microforma' => 'nullable|string|max:200', 'motivo' => 'required|string|min:5|max:2000',
            'inventario_pdf' => 'required|file']);
        $archivo = null;
        try {
            $movimiento = DB::transaction(function () use ($request, $legajo, $datos, &$archivo): MovimientoCustodiaLegajo {
                $l = Legajo::query()->lockForUpdate()->findOrFail($legajo);
                abort_unless($l->estado_legajo === 'pasivo' && $l->fecha_pasivo, 409, 'Solo se transfiere un legajo pasivo con fecha de pase registrada.');
                abort_if($l->fecha_transferencia_archivo_central !== null, 409, 'La transferencia ya está registrada y se conserva en el historial.');
                abort_unless($datos['fecha_transferencia'] > $l->fecha_pasivo->copy()->addYearsNoOverflow(3)->format('Y-m-d'), 422, 'La transferencia al archivo central requiere más de tres años en estado pasivo.');
                abort_if(in_array($l->formato_legajo, ['digital', 'mixto'], true) && empty($datos['referencia_microforma']), 422, 'Consigna la constancia de microforma para el archivo digital.');
                $archivo = CargaSoporteOperativo::guardar($request->file('inventario_pdf'), $request->user()->id);
                $m = new MovimientoCustodiaLegajo;
                $m->forceFill(['legajo_id' => $l->id, 'tipo_movimiento' => 'transferencia', 'base_autorizacion' => 'archivo_central', 'autorizado_por' => $request->user()->id,
                    'entidad_destino' => $datos['entidad_destino'], 'numero_documento_sustento' => $datos['numero_documento_sustento'], 'sustento_archivo_id' => $archivo->id,
                    'formato_legajo' => $l->formato_legajo, 'numero_inventario' => $datos['numero_inventario'], 'fecha_movimiento' => $datos['fecha_transferencia'],
                    'observaciones' => $datos['motivo'].(! empty($datos['referencia_microforma']) ? "\nConstancia de microforma: ".$datos['referencia_microforma'] : '')])->save();
                $antes = $l->getAttributes();
                $l->update(['ubicacion' => $datos['ubicacion'], 'fecha_transferencia_archivo_central' => $datos['fecha_transferencia']]);
                AuditoriaInstitucional::registrar('legajos', $l->id, 'archivo.transferencia_registrada', $request, $l->usuario_id, $antes, [...$l->getAttributes(), 'motivo' => $datos['motivo'], 'movimiento_id' => $m->id, 'inventario_archivo_id' => $archivo->id]);

                return $m;
            });
        } catch (Throwable $error) {
            if ($archivo) {
                Storage::disk($archivo->almacenamiento)->delete($archivo->storage_path);
            }
            throw $error;
        }

        return response()->json(['data' => $movimiento], 201);
    }

    public function inventario(Request $request, string $movimiento): StreamedResponse
    {
        $m = MovimientoCustodiaLegajo::query()->where('tipo_movimiento', 'transferencia')->with(['legajo', 'sustentoArchivo'])->findOrFail($movimiento);
        abort_unless($m->sustentoArchivo, 404);
        $contenido = AlmacenDocumento::leer($m->sustentoArchivo);
        BitacoraAccesoLegajo::query()->create(['legajo_id' => $m->legajo_id, 'usuario_id' => $request->user()->id, 'accion' => 'descargar', 'fecha_acceso' => now(), 'ip_origen' => $request->ip()]);

        return response()->streamDownload(static function () use ($contenido): void {
            echo $contenido;
        }, $m->sustentoArchivo->nombre_original,
            ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function validar(Request $request, array $reglas): array
    {
        abort_if(array_diff(array_keys($request->all()), array_keys($reglas)) !== [], 422, 'Campo no permitido.');

        return $request->validate($reglas);
    }
}
