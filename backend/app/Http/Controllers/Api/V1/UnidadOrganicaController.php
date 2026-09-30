<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UnidadOrganica;

class UnidadOrganicaController extends ResourceController
{
    protected string $model = UnidadOrganica::class;

    protected array $rules = [
        'codigo' => 'required|string|max:20',
        'nombre' => 'required|string|max:150',
        'tipo' => 'required|string|max:30',
        'unidad_padre_id' => 'nullable|uuid|exists:unidades_organicas,id',
        'activo' => 'sometimes|boolean',
    ];
}
