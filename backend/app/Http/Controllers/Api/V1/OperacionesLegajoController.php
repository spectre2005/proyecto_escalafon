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
        if (! empty($filtros['q'])) {
            $q = '%'.mb_strtolower(trim($filtros['q'])).'%';
            $consulta->where(fn ($c) => $c->whereRaw('LOWER(numero_legajo) LIKE ?', [$q])->orWhereHas('usuario', fn ($u) => $u->whereRaw("LOWER(nombres || ' ' || apellido_paterno || ' ' || COALESCE(apellido_materno, '')) LIKE ?", [$q])->orWhere('numero_doc_identidad', 'like', $q)));
        }

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
        $request->validate(['page' => 'sometimes|integer|min:1']);
        Legajo::query()->findOrFail($legajo);

        return response()->json(DocumentosOperativos::consulta($request, $legajo, soloActivos: false)->with('tipoDocumento:id,nombre')->orderByDesc('fecha_emision')->orderBy('id')->paginate(20, ['id', 'tipo_documento_id', 'num_resolucion', 'estado', 'fecha_emision']));
    }
}
