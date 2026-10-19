<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Legajo;
use App\Models\ProcesoFiscalizacion;
use App\Support\Documentos\DocumentosOperativos;
use App\Support\GestionFiscalizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FiscalizacionPanelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate(['q' => 'nullable|string|max:120', 'estado' => 'nullable|in:abierto,cerrado,cancelado', 'atrasado' => 'nullable|boolean', 'page' => 'sometimes|integer|min:1']);
        $consulta = ProcesoFiscalizacion::query()->with(['usuario:id,nombres,apellido_paterno,apellido_materno', 'legajo:id,numero_legajo', 'responsable:id,nombres,apellido_paterno', 'tipoDocumento:id,nombre,seccion_legajo_id']);
        if (! empty($filtros['q'])) {
            $q = '%'.mb_strtolower(trim($filtros['q'])).'%';
            $consulta->whereHas('usuario', fn ($u) => $u->whereRaw("LOWER(nombres || ' ' || apellido_paterno || ' ' || COALESCE(apellido_materno, '')) LIKE ?", [$q]));
        }
        if (! empty($filtros['estado'])) {
            $consulta->where('estado', $filtros['estado']);
        }
        if ($filtros['atrasado'] ?? false) {
            $consulta->where('estado', 'abierto')->whereDate('fecha_limite', '<', now('America/Lima')->toDateString());
        }

        return response()->json($consulta->orderBy('fecha_limite')->orderBy('id')->paginate(20));
    }

    public function tipos(Request $request): JsonResponse
    {
        $datos = $request->validate(['legajo_id' => 'required|uuid|exists:legajos,id']);
        $legajo = Legajo::query()->findOrFail($datos['legajo_id']);
        abort_unless($legajo->estado_legajo === 'activo', 409, 'Selecciona un legajo activo.');
        $tipos = DocumentosOperativos::consulta($request, $legajo->id, $legajo->usuario_id)
            ->whereDoesntHave('documentosUsuarioPorSubsanaADocumento')->whereHas('tipoDocumento', fn ($q) => $q->where('activo', true))
            ->selectRaw('tipo_documento_id, COUNT(*) as cantidad')->groupBy('tipo_documento_id')
            ->with('tipoDocumento:id,nombre,seccion_legajo_id')->get()
            ->map(fn ($grupo): array => ['id' => $grupo->tipo_documento_id, 'nombre' => $grupo->tipoDocumento->nombre,
                'seccion_legajo_id' => $grupo->tipoDocumento->seccion_legajo_id, 'cantidad' => (int) $grupo->cantidad])->sortBy('nombre')->values();

        return response()->json(['data' => $tipos]);
    }

    public function show(string $caso): JsonResponse
    {
        return response()->json(['data' => ProcesoFiscalizacion::query()->with(['tipoDocumento:id,nombre,seccion_legajo_id', 'solicitudesFiscalizacion.documento.tipoDocumento', 'solicitudesFiscalizacion.documentoRespuesta.tipoDocumento'])->findOrFail($caso)]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request, ['legajo_id' => 'required|uuid|exists:legajos,id', 'tipo_muestra' => 'required_without:tipo_documento_id|nullable|string|max:25',
            'tipo_documento_id' => 'nullable|uuid|exists:tipos_documento,id',
            'fecha_vinculacion' => 'required|date_format:Y-m-d|before_or_equal:fecha_inicio',
            'fecha_inicio' => 'required|date_format:Y-m-d|before_or_equal:'.now('America/Lima')->toDateString(),
            'fecha_limite' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio']);

        return response()->json(['data' => GestionFiscalizacion::crear($request, $datos)], 201);
    }

    public function accion(Request $request, string $caso): JsonResponse
    {
        $accion = $request->input('accion');
        $reglas = ['accion' => 'required|in:comunicar,responder,cerrar,reabrir,prorrogar,cancelar', 'version' => 'required|integer|min:1'];
        $pasado = '|date_format:Y-m-d|before_or_equal:'.now('America/Lima')->toDateString();
        if ($accion === 'comunicar') {
            $reglas += ['documento_id' => 'required|uuid', 'oficio_documento_id' => 'required|uuid', 'entidad_consultada' => 'required|string|max:200', 'medio' => 'required|string|max:30', 'numero_oficio' => 'required|string|max:40', 'fecha_envio' => 'required'.$pasado];
        } elseif ($accion === 'responder') {
            $reglas += ['solicitud_id' => 'required|uuid', 'documento_respuesta_id' => 'required|uuid', 'fecha_respuesta' => 'required'.$pasado, 'resultado' => 'required|in:conforme,observado,falso', 'descripcion_hallazgo' => 'required|string|max:5000'];
        } elseif ($accion === 'cerrar') {
            $reglas += ['informe_documento_id' => 'required|uuid', 'fecha_fin' => 'required'.$pasado, 'conclusiones' => 'required|string|max:5000'];
        } elseif ($accion === 'prorrogar') {
            $reglas['fecha_limite'] = 'required|date_format:Y-m-d|after_or_equal:'.now('America/Lima')->toDateString();
        }
        if (in_array($accion, ['reabrir', 'prorrogar', 'cancelar'], true)) {
            $reglas['motivo'] = 'required|string|max:1000';
        }

        return response()->json(['data' => GestionFiscalizacion::ejecutar($request, $caso, $this->validar($request, $reglas))]);
    }

    private function validar(Request $request, array $reglas): array
    {
        $extra = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($extra !== []) {
            throw ValidationException::withMessages(array_fill_keys($extra, 'Campo no permitido.'));
        }

        return $request->validate($reglas);
    }
}
