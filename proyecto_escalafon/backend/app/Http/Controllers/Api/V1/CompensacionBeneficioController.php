<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CompensacionBeneficio;

class CompensacionBeneficioController extends ResourceController
{
    protected string $model = CompensacionBeneficio::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'tipo' => 'required|string|max:15',
        'concepto' => 'required|string|max:200',
        'fecha' => 'required|date',
        'numero_documento' => 'nullable|string|max:60',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
