<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EvaluacionDesempeno;

class EvaluacionDesempenoController extends ResourceController
{
    protected string $model = EvaluacionDesempeno::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'ciclo_anio' => 'required|integer|between:-2147483648,2147483647',
        'fecha_evaluacion' => 'nullable|date',
        'calificacion' => 'required|string|max:40',
        'puntaje' => 'nullable|numeric',
        'plan_mejora' => 'sometimes|boolean',
        'evaluador_id' => 'nullable|uuid|exists:usuarios,id',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
