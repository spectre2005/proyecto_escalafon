<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ExperienciaLaboral;

class ExperienciaLaboralController extends ResourceController
{
    protected string $model = ExperienciaLaboral::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'entidad_empresa' => 'required|string|max:200',
        'sector' => 'required|string|max:10',
        'cargo' => 'required|string|max:150',
        'funciones' => 'nullable|string',
        'fecha_inicio' => 'required|date',
        'fecha_fin' => 'nullable|date',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
