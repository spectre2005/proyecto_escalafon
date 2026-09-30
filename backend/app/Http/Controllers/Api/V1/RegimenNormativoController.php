<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\RegimenNormativo;

class RegimenNormativoController extends ResourceController
{
    protected string $model = RegimenNormativo::class;

    protected array $rules = [
        'codigo' => 'required|string|max:20',
        'nombre' => 'required|string|max:150',
        'aplica_a' => 'required|string|max:15',
        'activo' => 'sometimes|boolean',
    ];
}
