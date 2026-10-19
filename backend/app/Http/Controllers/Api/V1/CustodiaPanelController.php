<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BitacoraAccesoLegajo;
use App\Models\MovimientoCustodiaLegajo;
use App\Models\SolicitudAccesoLegajo;
use App\Support\Documentos\AlmacenDocumento;
use App\Support\Documentos\DocumentosOperativos;
use App\Support\GestionCustodia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustodiaPanelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate(['q' => 'nullable|string|max:120', 'estado' => 'nullable|in:pendiente,aprobada,rechazada,revocada', 'page' => 'sometimes|integer|min:1']);
        $consulta = SolicitudAccesoLegajo::query()->with('legajo:id,numero_legajo,usuario_id');
        if (! empty($filtros['q'])) {
            $q = '%'.mb_strtolower(trim($filtros['q'])).'%';
            $consulta->where(fn ($c) => $c->whereRaw('LOWER(solicitante_nombre) LIKE ?', [$q])->orWhere('solicitante_documento', 'like', $q)->orWhereHas('legajo', fn ($l) => $l->whereRaw('LOWER(numero_legajo) LIKE ?', [$q])));
        }
        if (! empty($filtros['estado'])) {
            $consulta->where('estado', $filtros['estado']);
        }

        return response()->json($consulta->orderByDesc('created_at')->orderBy('id')->paginate(20));
    }

    public function show(string $solicitud): JsonResponse
    {
        return response()->json(['data' => SolicitudAccesoLegajo::query()->with('legajo:id,numero_legajo,usuario_id')->findOrFail($solicitud)]);
    }

    public function movimientos(Request $request): JsonResponse
    {
        $filtros = $request->validate(['pendientes' => 'nullable|boolean', 'atrasados' => 'nullable|boolean', 'page' => 'sometimes|integer|min:1']);
        $consulta = MovimientoCustodiaLegajo::query()->where('tipo_movimiento', 'prestamo')->with('legajo:id,numero_legajo');
        if (($filtros['pendientes'] ?? false) || ($filtros['atrasados'] ?? false)) {
            $consulta->whereNull('fecha_retorno')->whereNull('devuelto_en');
        }
        if ($filtros['atrasados'] ?? false) {
            $consulta->whereDate('fecha_devolucion_prevista', '<', now('America/Lima')->toDateString());
        }

        return response()->json($consulta->orderBy('fecha_devolucion_prevista')->orderBy('id')->paginate(20));
    }

    public function alcance(Request $request, string $solicitud): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $registro = SolicitudAccesoLegajo::query()->findOrFail($solicitud);

        return response()->json(DocumentosOperativos::consulta($request, $registro->legajo_id, soloActivos: false)
            ->whereIn('id', $registro->documentos_autorizados ?? [])->with('tipoDocumento:id,nombre')
            ->orderBy('id')->paginate(20, ['id', 'tipo_documento_id', 'num_resolucion', 'estado']));
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request, ['legajo_id' => 'required|uuid|exists:legajos,id', 'solicitante_nombre' => 'required|string|max:200', 'solicitante_documento' => 'required|string|max:20',
            'entidad_procedencia' => 'nullable|string|max:200', 'tipo_solicitante' => 'required|in:interno,externo,titular', 'detalle_requerido' => 'required|string|max:3000', 'sustento' => 'required|string|max:5000',
            'forma_entrega' => 'required|in:consulta,copia,prestamo', 'incluye_datos_sensibles' => 'required|boolean', 'compromiso_confidencialidad' => 'required|accepted',
            'documentos_autorizados' => 'required|array|min:1|max:100', 'documentos_autorizados.*' => 'required|uuid|distinct']);
        $datos['compromiso_confidencialidad'] = true;

        return response()->json(['data' => GestionCustodia::solicitar($request, $datos)], 201);
    }

    public function accion(Request $request, string $solicitud): JsonResponse
    {
        $reglas = ['accion' => 'required|in:aprobar,rechazar,revocar', 'version' => 'required|integer|min:1', 'motivo' => 'required|string|max:1000'];
        if ($request->input('accion') === 'aprobar') {
            $reglas['vigente_hasta'] = 'required|date_format:Y-m-d|after_or_equal:'.now('America/Lima')->toDateString();
        }

        return response()->json(['data' => GestionCustodia::resolver($request, $solicitud, $this->validar($request, $reglas))]);
    }

    public function prestar(Request $request, string $solicitud): JsonResponse
    {
        $datos = $this->validar($request, ['fecha_movimiento' => 'required|date_format:Y-m-d|before_or_equal:'.now('America/Lima')->toDateString(),
            'fecha_devolucion_prevista' => 'required|date_format:Y-m-d|after_or_equal:fecha_movimiento', 'entidad_destino' => 'required|string|max:200',
            'numero_documento_sustento' => 'required|string|max:60', 'numero_inventario' => 'required|string|max:40', 'formato_legajo' => 'required|in:fisico,mixto', 'observaciones' => 'required|string|max:3000']);

        return response()->json(['data' => GestionCustodia::prestar($request, $solicitud, $datos)], 201);
    }

    public function devolver(Request $request, string $movimiento): JsonResponse
    {
        $datos = $this->validar($request, ['version' => 'required|integer|min:1', 'fecha_retorno' => 'required|date_format:Y-m-d|before_or_equal:'.now('America/Lima')->toDateString(), 'motivo' => 'required|string|max:1000']);

        return response()->json(['data' => GestionCustodia::devolver($request, $movimiento, $datos)]);
    }

    public function archivo(Request $request, string $solicitud, string $documento): StreamedResponse
    {
        [$archivo, $contenido] = DB::transaction(function () use ($request, $solicitud, $documento): array {
            $registro = SolicitudAccesoLegajo::query()->lockForUpdate()->findOrFail($solicitud);
            GestionCustodia::exigirVigencia($request, $registro);
            abort_unless(in_array($documento, $registro->documentos_autorizados ?? [], true), 403, 'El documento no forma parte del alcance autorizado.');
            $doc = DocumentosOperativos::consulta($request, $registro->legajo_id, soloActivos: false)->with('archivo')->findOrFail($documento);
            $contenido = AlmacenDocumento::leer($doc->archivo);
            BitacoraAccesoLegajo::query()->create(['legajo_id' => $registro->legajo_id, 'documento_id' => $doc->id, 'usuario_id' => $request->user()->id, 'solicitud_acceso_id' => $registro->id,
                'accion' => 'entrega', 'fecha_acceso' => now(), 'ip_origen' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300)]);

            return [$doc->archivo, $contenido];
        });

        return response()->streamDownload(static function () use ($contenido): void {
            echo $contenido;
        }, $archivo->nombre_original, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function validar(Request $request, array $reglas): array
    {
        $extra = array_diff(array_keys($request->all()), array_map(fn (string $campo): string => explode('.', $campo)[0], array_keys($reglas)));
        if ($extra !== []) {
            throw ValidationException::withMessages(array_fill_keys($extra, 'Campo no permitido.'));
        }

        return $request->validate($reglas);
    }
}
