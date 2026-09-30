<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ProgresionCarrera;

class ProgresionCarreraController extends ResourceController
{
    protected string $model = ProgresionCarrera::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'vinculo_laboral_id' => 'required|uuid|exists:vinculos_laborales,id',
        'nivel_anterior' => 'nullable|string|max:20',
        'nivel_nuevo' => 'required|string|max:20',
        'fecha_efectiva' => 'required|date',
        'numero_resolucion' => 'required|string|max:60',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
