<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Colegiatura;

class ColegiaturaController extends ResourceController
{
    protected string $model = Colegiatura::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'colegio_profesional' => 'required|string|max:150',
        'numero_colegiatura' => 'required|string|max:30',
        'fecha_colegiatura' => 'nullable|date',
        'habilitado' => 'sometimes|boolean',
        'fecha_verificacion' => 'nullable|date',
        'vigente_hasta' => 'nullable|date',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
