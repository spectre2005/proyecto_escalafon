<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Afiliacion;

class AfiliacionController extends ResourceController
{
    protected string $model = Afiliacion::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'tipo' => 'required|string|max:15',
        'entidad' => 'required|string|max:150',
        'numero_afiliacion' => 'nullable|string|max:40',
        'fecha_inicio' => 'required|date',
        'fecha_fin' => 'nullable|date',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
