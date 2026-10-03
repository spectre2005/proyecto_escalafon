<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PeriodoVacacional;

class PeriodoVacacionalController extends ResourceController
{
    protected string $model = PeriodoVacacional::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'vinculo_laboral_id' => 'required|uuid|exists:vinculos_laborales,id',
        'periodo_anio' => 'required|integer|between:-2147483648,2147483647',
        'dias_derecho' => 'sometimes|integer|between:-2147483648,2147483647',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
