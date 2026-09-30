<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\GoceVacacional;

class GoceVacacionalController extends ResourceController
{
    protected string $model = GoceVacacional::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'periodo_vacacional_id' => 'required|uuid|exists:periodos_vacacionales,id',
        'tipo' => 'required|string|max:15',
        'fecha_inicio' => 'required|date',
        'fecha_fin' => 'required|date',
        'dias' => 'required|integer|between:-2147483648,2147483647',
        'numero_documento' => 'nullable|string|max:60',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
