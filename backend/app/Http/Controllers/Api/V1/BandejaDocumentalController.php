<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentoUsuario;
use App\Support\Documentos\DocumentosOperativos;
use App\Support\Documentos\GestionFedateo;
use App\Support\Documentos\RevisionDocumento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BandejaDocumentalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate(['q' => 'nullable|string|max:120', 'seccion' => 'nullable|integer|between:1,12', 'estado' => 'nullable|in:pendiente,cargado,aceptado,verificado,observado,en_fiscalizacion', 'page' => 'sometimes|integer|min:1']);
        $consulta = DocumentosOperativos::consulta($request, null, todas: true)
            ->whereDoesntHave('documentosUsuarioPorSubsanaADocumento')
            ->when($datos['seccion'] ?? null, fn ($q, $seccion) => $q->where('seccion_legajo_id', $seccion));
        if (! empty($datos['q'])) {
            $busqueda = '%'.mb_strtolower(trim($datos['q'])).'%';
            $consulta->whereHas('usuario', fn (Builder $q) => $q->whereRaw("LOWER(TRIM(nombres || ' ' || apellido_paterno || ' ' || COALESCE(apellido_materno, ''))) LIKE ?", [$busqueda])->orWhere('numero_doc_identidad', 'like', $busqueda));
        }
        $resumen = ['documentos' => (clone $consulta)->count(),
            'completos' => (clone $consulta)->whereIn('estado', ['aceptado', 'verificado'])->whereNotNull('fecha_recepcion')->whereNotNull('recepcionado_por')->count(),
            'observados' => (clone $consulta)->where('estado', 'observado')->count(),
            'sin_recepcion' => (clone $consulta)->where(fn ($q) => $q->whereNull('fecha_recepcion')->orWhereNull('recepcionado_por'))->count()];
        $consulta->when($datos['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado));
        $pagina = $consulta->with(['usuario:id,nombres,apellido_paterno,apellido_materno,numero_doc_identidad', 'tipoDocumento:id,nombre,requiere_fedateo', 'archivo:id,num_paginas'])
            ->orderBy('fecha_incorporacion')->orderBy('id')->paginate(20);
        $pagina->through(fn (DocumentoUsuario $doc): array => [...$doc->only(['id', 'usuario_id', 'legajo_id', 'seccion_legajo_id', 'estado', 'fedateado', 'folio_inicio', 'folio_fin', 'fecha_recepcion', 'created_by', 'fecha_fedateo', 'fedateo_sustento_documento_id']),
            'usuario' => $doc->usuario, 'tipo' => $doc->tipoDocumento, 'paginas' => $doc->archivo->num_paginas]);

        return response()->json([...$pagina->toArray(), 'resumen' => $resumen, 'hoy' => now('America/Lima')->toDateString()]);
    }

    public function fedatear(Request $request, string $documento): JsonResponse
    {
        return response()->json(['data' => GestionFedateo::registrar($request, $documento)->only(['id', 'fedateado', 'fecha_fedateo', 'fedatario_id', 'fedateo_sustento_documento_id'])]);
    }

    public function revisar(Request $request, string $documento): JsonResponse
    {
        $doc = DocumentosOperativos::consulta($request, null, todas: true)->findOrFail($documento);
        if ($doc->seccion_legajo_id === 8) {
            abort_unless($request->user()->can('seccion08.sanciones.revisar') || ! str_starts_with($doc->tipoDocumento->codigo, 'SAN_'), 403);
        }

        return RevisionDocumento::cambiarEstado($request, $doc->usuario_id, $doc->id, $doc->seccion_legajo_id, 'documento.revision');
    }
}
