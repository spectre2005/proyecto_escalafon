<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SubseccionLegajo;

class SubseccionLegajoController extends ResourceController
{
    protected string $model = SubseccionLegajo::class;

    protected array $rules = [
        'seccion_legajo_id' => 'required|integer|between:-32768,32767|exists:secciones_legajo,id',
        'codigo' => 'required|string|max:10',
        'nombre' => 'required|string|max:150',
        'orden' => 'sometimes|integer|between:-32768,32767',
    ];
}
