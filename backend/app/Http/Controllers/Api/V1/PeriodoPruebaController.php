<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PeriodoPrueba;

class PeriodoPruebaController extends ResourceController
{
    protected string $model = PeriodoPrueba::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'vinculo_laboral_id' => 'required|uuid|exists:vinculos_laborales,id',
        'fecha_inicio' => 'required|date',
        'fecha_fin' => 'required|date',
        'resultado' => 'sometimes|string|max:15',
        'fecha_resultado' => 'nullable|date',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
