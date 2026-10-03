<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Capacitacion;

class CapacitacionController extends ResourceController
{
    protected string $model = Capacitacion::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'tipo' => 'required|string|max:25',
        'nombre' => 'required|string|max:250',
        'entidad' => 'nullable|string|max:200',
        'horas' => 'nullable|integer|between:-2147483648,2147483647',
        'fecha_inicio' => 'nullable|date',
        'fecha_fin' => 'nullable|date',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
