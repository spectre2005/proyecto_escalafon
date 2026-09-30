<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DeclaracionVerificacion;

class DeclaracionVerificacionController extends ResourceController
{
    protected string $model = DeclaracionVerificacion::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'tipo' => 'required|string|max:25',
        'anio' => 'nullable|integer|between:-2147483648,2147483647',
        'fecha' => 'required|date',
        'resultado' => 'required|string|max:15',
        'vigente_hasta' => 'nullable|date',
        'observacion' => 'nullable|string|max:300',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
