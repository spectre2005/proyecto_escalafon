<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaEvento;
use App\Models\RequerimientoDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\DocumentosOperativos;
use App\Support\GestionRequerimientos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionRequerimientoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request, [
            'usuario_id' => 'required|uuid|exists:usuarios,id',
            'tipo_documento_id' => ['nullable', 'uuid', Rule::exists('tipos_documento', 'id')->where('activo', true)],
            'origen' => 'required|string|max:15', 'descripcion' => 'required|string|max:300',
            'fecha_requerimiento' => 'required|date_format:Y-m-d|before_or_equal:'.now('America/Lima')->toDateString(),
            'fecha_limite' => 'required|date_format:Y-m-d|after_or_equal:fecha_requerimiento',
        ]);
        if (! empty($datos['tipo_documento_id'])) {
            $tipo = TipoDocumento::query()->findOrFail($datos['tipo_documento_id']);
            abort_if(($tipo->es_dato_sensible || $tipo->es_dato_salud || in_array($tipo->seccion_legajo_id, [1, 9, 10], true)) && ! $request->user()->veDatosSensibles(), 403);
        }

        return response()->json(['data' => GestionRequerimientos::crear($request, $datos)], 201);
    }

    public function accion(Request $request, string $requerimiento): JsonResponse
    {
        $accion = $request->input('accion');
        $reglas = ['accion' => 'required|in:editar,responder,observar,cumplir,prorrogar,cancelar', 'version' => 'required|integer|min:1'];
        if ($accion === 'editar') {
            $reglas['descripcion'] = 'required|string|max:300';
        }
        if ($accion === 'responder') {
            $reglas['documento_id'] = 'required|uuid';
        }
        if (in_array($accion, ['editar', 'observar', 'prorrogar', 'cancelar'], true)) {
            $reglas['motivo'] = 'required|string|max:1000';
        }
        if ($accion === 'prorrogar') {
            $reglas['prorroga_hasta'] = 'required|date_format:Y-m-d|after_or_equal:'.now('America/Lima')->toDateString();
        }

        return response()->json(['data' => GestionRequerimientos::ejecutar($request, $requerimiento, $this->validar($request, $reglas))]);
    }

    public function personas(Request $request): JsonResponse
    {
        $datos = $request->validate(['q' => 'required|string|min:2|max:120', 'page' => 'sometimes|integer|min:1']);

        return response()->json(Usuario::query()->buscarIdentidad($datos['q'])
            ->orderBy('apellido_paterno')->orderBy('id')->paginate(20, ['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'numero_doc_identidad']));
    }

    public function tipos(Request $request): JsonResponse
    {
        $datos = $request->validate(['usuario_id' => 'nullable|uuid|exists:usuarios,id']);
        $usuario = $datos['usuario_id'] ?? null;
        $conteos = $usuario ? DocumentosOperativos::consulta($request, null, $usuario)
            ->whereDoesntHave('documentosUsuarioPorSubsanaADocumento')
            ->selectRaw('tipo_documento_id, COUNT(*) as cantidad')->groupBy('tipo_documento_id')->pluck('cantidad', 'tipo_documento_id') : collect();
        $consulta = TipoDocumento::query()->where('activo', true);
        if (! $request->user()->veDatosSensibles()) {
            $consulta->whereNotIn('seccion_legajo_id', [1, 9, 10])->where('es_dato_sensible', false)->where('es_dato_salud', false);
        }

        return response()->json(['data' => $consulta->with('seccionLegajo:id,nombre')->orderBy('seccion_legajo_id')->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'seccion_legajo_id'])->map(fn (TipoDocumento $tipo): array => [...$tipo->toArray(),
            'documentos_registrados' => $usuario ? (int) ($conteos[$tipo->id] ?? 0) : null])]);
    }

    public function documentos(Request $request, string $requerimiento): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $registro = RequerimientoDocumento::query()->findOrFail($requerimiento);

        return response()->json(GestionRequerimientos::documentos($request, $registro)->with('tipoDocumento:id,nombre')->orderByDesc('fecha_emision')->orderBy('id')->paginate(20, ['id', 'tipo_documento_id', 'num_resolucion', 'nombre', 'fecha_emision', 'estado', 'folio_inicio', 'folio_fin', 'seccion_legajo_id', 'fecha_recepcion']));
    }

    public function historial(Request $request, string $requerimiento): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1']);
        RequerimientoDocumento::query()->findOrFail($requerimiento);
        $eventos = AuditoriaEvento::query()->where('entidad', 'requerimientos_documentos')->where('entidad_id', $requerimiento)
            ->with('actorUsuario:id,nombres,apellido_paterno,apellido_materno')->orderByDesc('id')->paginate(20);
        $eventos->through(fn (AuditoriaEvento $evento): array => [
            'id' => $evento->id, 'fecha' => $evento->fecha_evento, 'accion' => $evento->accion,
            'actor' => $evento->actorUsuario, 'antes' => json_decode($evento->datos_antes ?? 'null', true),
            'despues' => json_decode($evento->datos_despues ?? 'null', true),
        ]);

        return response()->json($eventos);
    }

    private function validar(Request $request, array $reglas): array
    {
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }

        return $request->validate($reglas);
    }
}
