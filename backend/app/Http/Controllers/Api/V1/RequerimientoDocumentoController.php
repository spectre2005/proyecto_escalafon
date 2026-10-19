<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\RequerimientoDocumento;
use App\Support\GestionRequerimientos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class RequerimientoDocumentoController extends ResourceController
{
    protected string $model = RequerimientoDocumento::class;

    public function index(): AnonymousResourceCollection
    {
        $filters = request()->validate([
            'q' => 'nullable|string|max:120',
            'estado' => 'nullable|string|max:15',
            'origen' => 'nullable|string|max:15',
            'page' => 'sometimes|integer|min:1',
            'atrasados' => 'nullable|boolean',
        ]);

        $query = RequerimientoDocumento::query()
            ->with($this->relaciones())
            ->when($filters['estado'] ?? null, fn (Builder $query, string $state): Builder => $query->where('estado', $state))
            ->when($filters['origen'] ?? null, fn (Builder $query, string $origin): Builder => $query->where('origen', $origin));

        foreach (preg_split('/\s+/u', mb_strtolower(trim($filters['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
            $term = '%'.$palabra.'%';
            $query->where(fn (Builder $q) => $q->whereRaw('LOWER(descripcion) LIKE ?', [$term])
                ->orWhereHas('usuario', fn (Builder $persona) => $persona->buscarIdentidad($palabra))
                ->orWhereHas('tipoDocumento', fn (Builder $tipo) => $tipo->whereRaw('LOWER(nombre) LIKE ?', [$term])));
        }
        if ($filters['atrasados'] ?? false) {
            $query->whereIn('estado', GestionRequerimientos::ABIERTOS)->whereNull('cerrado_en')
                ->whereRaw('COALESCE(prorroga_hasta, fecha_limite) < ?', [now('America/Lima')->toDateString()]);
        }

        $records = $query->orderByDesc('fecha_requerimiento')->orderBy('id')->paginate(20)->withQueryString();

        return JsonResource::collection($records)->additional([
            'filters' => [
                'estados' => RequerimientoDocumento::query()->whereNotNull('estado')->distinct()->orderBy('estado')->pluck('estado'),
                'origenes' => RequerimientoDocumento::query()->whereNotNull('origen')->distinct()->orderBy('origen')->pluck('origen'),
            ],
        ]);
    }

    public function show(string $record): JsonResource
    {
        return new JsonResource(RequerimientoDocumento::query()->with($this->relaciones())->findOrFail($record));
    }

    private function relaciones(): array
    {
        return ['usuario:id,nombres,apellido_paterno,apellido_materno,numero_doc_identidad',
            'tipoDocumento:id,nombre,seccion_legajo_id', 'documento:id,estado,num_resolucion,nombre,seccion_legajo_id'];
    }

    public function store(Request $request): JsonResponse
    {
        abort(409, 'Registra solicitudes mediante /api/v1/requerimientos para conservar autor e historial.');
    }

    public function update(Request $request, string $record): JsonResponse
    {
        abort(409, 'Usa las acciones de Requerimientos con control de versión.');
    }

    public function destroy(string $record): JsonResponse
    {
        abort(409, 'Los requerimientos se cancelan con motivo; no se eliminan.');
    }
}
