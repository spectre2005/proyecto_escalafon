<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\LegajoSeccion;

class LegajoSeccionController extends ResourceController
{
    protected string $model = LegajoSeccion::class;

    protected array $rules = [
        'legajo_id' => 'required|uuid|exists:legajos,id',
        'seccion_legajo_id' => 'required|integer|between:-32768,32767|exists:secciones_legajo,id',
        'ultimo_folio' => 'sometimes|integer|between:-2147483648,2147483647',
        'ultima_actualizacion' => 'nullable|date',
    ];
}
