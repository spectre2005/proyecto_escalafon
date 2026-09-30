<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ParametroSistema;

class ParametroSistemaController extends ResourceController
{
    protected string $model = ParametroSistema::class;

    protected array $rules = [
        'clave' => 'required|string|max:80',
        'valor' => 'required|string|max:200',
        'tipo_dato' => 'required|string|max:10',
        'descripcion' => 'nullable|string|max:300',
        'actualizado_por' => 'nullable|uuid|exists:usuarios,id',
    ];
}
