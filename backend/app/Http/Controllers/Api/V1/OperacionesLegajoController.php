<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaEvento;
use App\Models\Legajo;
use App\Models\ReferenciaLegajoPasivo;
use App\Support\ConsultaAuditoria;
use App\Support\Documentos\DocumentosOperativos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperacionesLegajoController extends Controller
{
    public function legajos(Request $request): JsonResponse
    {
        $filtros = $request->validate(['q' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1']);
        $consulta = Legajo::query()->with('usuario:id,nombres,apellido_paterno,apellido_materno,numero_doc_identidad');
        $consulta->buscar($filtros['q'] ?? null);

        return response()->json($consulta->orderBy('numero_legajo')->orderBy('id')->paginate(20, ['id', 'usuario_id', 'numero_legajo', 'estado_legajo']));
    }

    public function historial(Request $request, string $entidad, string $registro): JsonResponse
    {
        abort_unless(in_array($entidad, ['legajos', 'referencias_legajo_pasivo', 'parametros_sistema', 'vinculos_laborales', 'documentos_usuario', 'procesos_fiscalizacion', 'solicitudes_fiscalizacion', 'solicitudes_acceso_legajo', 'movimientos_custodia_legajo', 'usuario_roles'], true), 404);
        if ($entidad === 'documentos_usuario') {
            DocumentosOperativos::consulta($request, null, soloActivos: false, todas: true)->findOrFail($registro);
        }
        if ($entidad === 'referencias_legajo_pasivo') {
            ReferenciaLegajoPasivo::query()->whereIn('tipo_documento_id', DocumentosOperativos::tipos($request)->select('id'))->findOrFail($registro);
        }
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $pagina = AuditoriaEvento::query()->where('entidad', $entidad)->where('entidad_id', $registro)
            ->with('actorUsuario:id,nombres,apellido_paterno,apellido_materno')->orderByDesc('id')->paginate(20);
        $pagina->through(fn (AuditoriaEvento $evento): array => ['id' => $evento->id, 'fecha' => $evento->fecha_evento, 'accion' => $evento->accion, 'usuario_titular_id' => $evento->usuario_titular_id,
            'actor' => $evento->actorUsuario, 'antes' => ConsultaAuditoria::redactar($evento->datos_antes), 'despues' => ConsultaAuditoria::redactar($evento->datos_despues)]);

        return response()->json($pagina);
    }

    public function documentos(Request $request, string $legajo): JsonResponse
    {
        $filtros = $request->validate(['page' => 'sometimes|integer|min:1', 'q' => 'nullable|string|max:120', 'tipo_documento_id' => 'nullable|uuid', 'excluir_documento_id' => 'nullable|uuid']);
        Legajo::query()->findOrFail($legajo);

        $consulta = DocumentosOperativos::consulta($request, $legajo, soloActivos: false);
        if (! empty($filtros['tipo_documento_id'])) {
            $consulta->where('tipo_documento_id', $filtros['tipo_documento_id'])->whereDoesntHave('documentosUsuarioPorSubsanaADocumento');
        }
        if (! empty($filtros['excluir_documento_id'])) {
            $consulta->where('id', '!=', $filtros['excluir_documento_id']);
        }
        foreach (preg_split('/\s+/u', mb_strtolower(trim($filtros['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
            $patron = '%'.$palabra.'%';
            $consulta->where(fn ($q) => $q->whereRaw('LOWER(nombre) LIKE ?', [$patron])->orWhereRaw('LOWER(num_resolucion) LIKE ?', [$patron])
                ->orWhereHas('tipoDocumento', fn ($t) => $t->whereRaw('LOWER(nombre) LIKE ?', [$patron])));
        }

        return response()->json($consulta->with('tipoDocumento:id,nombre,es_dato_sensible,es_dato_salud')->orderByDesc('fecha_emision')->orderBy('id')
            ->paginate(20, ['id', 'tipo_documento_id', 'num_resolucion', 'nombre', 'estado', 'fecha_emision', 'seccion_legajo_id', 'folio_inicio', 'folio_fin']));
    }
}
