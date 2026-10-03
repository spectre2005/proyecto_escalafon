<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SeccionLegajo;

class SeccionLegajoController extends ResourceController
{
    protected string $model = SeccionLegajo::class;

    protected array $rules = [
        'id' => 'required|integer|between:-32768,32767',
        'nombre' => 'required|string|max:150',
        'descripcion' => 'nullable|string',
        'es_nucleo_comun' => 'sometimes|boolean',
        'orden' => 'required|integer|between:-32768,32767',
    ];
}
