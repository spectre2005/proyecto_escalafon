<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SolicitudFiscalizacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\JsonResource;

class SolicitudFiscalizacionController extends ResourceController
{
    protected string $model = SolicitudFiscalizacion::class;

    protected array $rules = [
        'proceso_fiscalizacion_id' => 'required|uuid|exists:procesos_fiscalizacion,id',
        'documento_id' => 'required|uuid|exists:documentos_usuario,id',
        'entidad_consultada' => 'required|string|max:200',
        'medio' => 'required|string|max:30',
        'numero_oficio' => 'nullable|string|max:40',
        'fecha_envio' => 'required|date',
        'oficio_archivo_id' => 'nullable|uuid|exists:archivos_documento,id',
        'fecha_respuesta' => 'nullable|date',
        'documento_respuesta_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'resultado' => 'sometimes|string|max:15',
        'descripcion_hallazgo' => 'nullable|string',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
    ];

    protected function indexQuery(): Builder
    {
        return SolicitudFiscalizacion::query()->whereIn('proceso_fiscalizacion_id', app(FiscalizacionController::class)->consultaVisible(request())->select('id'))->orderBy('id');
    }

    public function show(string $record): JsonResource
    {
        return new JsonResource($this->indexQuery()->findOrFail($record));
    }
}
