<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Induccion;

class InduccionController extends ResourceController
{
    protected string $model = Induccion::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'vinculo_laboral_id' => 'required|uuid|exists:vinculos_laborales,id',
        'tipo' => 'required|string|max:15',
        'fecha' => 'required|date',
        'responsable' => 'nullable|string|max:150',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
