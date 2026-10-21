<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\RequerimientoDocumento;
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
        ]);

        $query = RequerimientoDocumento::query()
            ->with([
                'usuario:id,nombres,apellido_paterno,apellido_materno',
                'tipoDocumento:id,nombre',
                'documento:id,estado,num_resolucion',
            ])
            ->when($filters['estado'] ?? null, fn (Builder $query, string $state): Builder => $query->where('estado', $state))
            ->when($filters['origen'] ?? null, fn (Builder $query, string $origin): Builder => $query->where('origen', $origin));

        if (! empty($filters['q'])) {
            $term = '%'.mb_strtolower(trim($filters['q'])).'%';
            $query->where(function (Builder $query) use ($term): void {
                $query->whereRaw('LOWER(descripcion) LIKE ?', [$term])
                    ->orWhereHas('usuario', function (Builder $userQuery) use ($term): void {
                        $userQuery->whereRaw('LOWER(nombres) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(apellido_paterno) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(apellido_materno) LIKE ?', [$term]);
                    });
            });
        }

        $records = $query->orderByDesc('fecha_requerimiento')->orderBy('id')->paginate(20)->withQueryString();

        return JsonResource::collection($records)->additional([
            'filters' => [
                'estados' => RequerimientoDocumento::query()->whereNotNull('estado')->distinct()->orderBy('estado')->pluck('estado'),
                'origenes' => RequerimientoDocumento::query()->whereNotNull('origen')->distinct()->orderBy('origen')->pluck('origen'),
            ],
        ]);
    }

    public function update(Request $request, string $record): JsonResource|JsonResponse
    {
        abort_if(RequerimientoDocumento::query()->findOrFail($record)->seguimiento !== null, 409,
            'Gestiona esta solicitud desde Requerimientos para conservar su historial.');

        return parent::update($request, $record);
    }

    public function destroy(string $record): JsonResponse
    {
        abort_if(RequerimientoDocumento::query()->findOrFail($record)->seguimiento !== null, 409,
            'Las solicitudes con seguimiento se cancelan y conservan su historial.');

        return parent::destroy($record);
    }

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'tipo_documento_id' => 'nullable|uuid|exists:tipos_documento,id',
        'origen' => 'required|string|max:15',
        'descripcion' => 'required|string|max:300',
        'fecha_requerimiento' => 'required|date',
        'plazo_dias_habiles' => 'required|integer|between:-2147483648,2147483647',
        'fecha_limite' => 'required|date',
        'prorroga_hasta' => 'nullable|date',
        'estado' => 'sometimes|string|max:15',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'solicitado_por' => 'required|uuid|exists:usuarios,id',
        'cerrado_en' => 'nullable|date',
    ];
}
