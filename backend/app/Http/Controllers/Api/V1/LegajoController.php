<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Legajo;

class LegajoController extends ResourceController
{
    protected string $model = Legajo::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'numero_legajo' => 'required|string|max:20',
        'estado_legajo' => 'sometimes|string|max:10',
        'formato_legajo' => 'sometimes|string|max:10',
        'ubicacion' => 'nullable|string|max:200',
        'fecha_apertura' => 'required|date',
        'fecha_pasivo' => 'nullable|date',
        'fecha_transferencia_archivo_central' => 'nullable|date',
    ];
}
