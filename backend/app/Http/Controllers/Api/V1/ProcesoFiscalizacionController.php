<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ProcesoFiscalizacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcesoFiscalizacionController extends ResourceController
{
    protected string $model = ProcesoFiscalizacion::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'legajo_id' => 'required|uuid|exists:legajos,id',
        'tipo_muestra' => 'required|string|max:25',
        'fecha_vinculacion' => 'required|date',
        'fecha_inicio' => 'required|date',
        'fecha_limite' => 'required|date',
        'fecha_fin' => 'nullable|date',
        'estado' => 'sometimes|string|max:15',
        'responsable_id' => 'required|uuid|exists:usuarios,id',
        'informe_documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'conclusiones' => 'nullable|string',
        'comunicado_procuraduria_en' => 'nullable|date',
        'comunicado_stpad_en' => 'nullable|date',
    ];

    protected function indexQuery(): Builder
    {
        return app(FiscalizacionController::class)->consultaVisible(request())->orderBy('id');
    }

    public function show(string $record): JsonResource
    {
        return new JsonResource($this->indexQuery()->findOrFail($record));
    }
}
