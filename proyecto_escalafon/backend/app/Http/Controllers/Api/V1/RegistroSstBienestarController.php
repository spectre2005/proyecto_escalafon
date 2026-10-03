<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\RegistroSstBienestar;

class RegistroSstBienestarController extends ResourceController
{
    protected string $model = RegistroSstBienestar::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'tipo' => 'required|string|max:30',
        'fecha' => 'required|date',
        'descripcion' => 'nullable|string|max:300',
        'estado_tramite' => 'sometimes|string|max:15',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
