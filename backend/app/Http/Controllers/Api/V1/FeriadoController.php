<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Feriado;

class FeriadoController extends ResourceController
{
    protected string $model = Feriado::class;

    protected array $rules = [
        'fecha' => 'required|date',
        'descripcion' => 'required|string|max:120',
    ];
}
