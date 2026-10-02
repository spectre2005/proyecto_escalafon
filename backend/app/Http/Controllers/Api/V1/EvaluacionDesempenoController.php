<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\EvaluacionDesempenoResource;
use App\Models\EvaluacionDesempeno;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EvaluacionDesempenoController extends RegistroAnulableController
{
    protected string $model = EvaluacionDesempeno::class;

    protected string $orden = 'ciclo_anio';

    public function index(): AnonymousResourceCollection
    {
        $filters = request()->validate([
            'q' => 'nullable|string|max:120',
            'ciclo_anio' => 'nullable|integer',
            'page' => 'sometimes|integer|min:1',
        ]);

        $query = EvaluacionDesempeno::query()
            ->where('anulado', false)
            ->with([
                'usuario:id,nombres,apellido_paterno,apellido_materno',
                'evaluador:id,nombres,apellido_paterno,apellido_materno',
                'documento:id,num_resolucion,estado',
            ])
            ->when($filters['ciclo_anio'] ?? null, fn (Builder $query, int $cycle): Builder => $query->where('ciclo_anio', $cycle));

        if (! empty($filters['q'])) {
            $term = '%'.mb_strtolower(trim($filters['q'])).'%';
            $query->whereHas('usuario', function (Builder $userQuery) use ($term): void {
                $userQuery->whereRaw('LOWER(nombres) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(apellido_paterno) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(apellido_materno) LIKE ?', [$term]);
            });
        }

        $records = $query->orderByDesc('ciclo_anio')
            ->orderByDesc('fecha_evaluacion')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return EvaluacionDesempenoResource::collection($records)->additional([
            'filters' => [
                'ciclos' => EvaluacionDesempeno::query()
                    ->where('anulado', false)
                    ->distinct()
                    ->orderByDesc('ciclo_anio')
                    ->pluck('ciclo_anio'),
            ],
        ]);
    }

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'ciclo_anio' => 'required|integer|between:-2147483648,2147483647',
        'fecha_evaluacion' => 'nullable|date',
        'calificacion' => 'required|string|max:40',
        'puntaje' => 'nullable|numeric',
        'plan_mejora' => 'sometimes|boolean',
        'evaluador_id' => 'nullable|uuid|exists:usuarios,id',
    ];
}
