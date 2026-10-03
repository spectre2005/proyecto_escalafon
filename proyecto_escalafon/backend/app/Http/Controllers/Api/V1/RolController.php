<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Rol;

class RolController extends ResourceController
{
    protected string $model = Rol::class;

    protected array $rules = [
        'codigo' => 'required|string|max:40',
        'nombre' => 'required|string|max:100',
        'descripcion' => 'nullable|string',
        've_datos_sensibles' => 'sometimes|boolean',
        'activo' => 'sometimes|boolean',
    ];
}
