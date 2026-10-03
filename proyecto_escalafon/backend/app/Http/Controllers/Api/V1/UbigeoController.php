<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Ubigeo;

class UbigeoController extends ResourceController
{
    protected string $model = Ubigeo::class;

    protected array $rules = [
        'region' => 'required|string|max:100',
        'provincia' => 'required|string|max:100',
        'distrito' => 'required|string|max:100',
        'descripcion' => 'nullable|string',
    ];
}
