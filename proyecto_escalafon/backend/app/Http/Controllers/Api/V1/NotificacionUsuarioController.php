<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\NotificacionUsuario;

class NotificacionUsuarioController extends ResourceController
{
    protected string $model = NotificacionUsuario::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'tipo' => 'required|string|max:30',
        'titulo' => 'required|string|max:150',
        'mensaje' => 'required|string',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'observacion_id' => 'nullable|uuid|exists:observaciones_documento,id',
        'requerimiento_id' => 'nullable|uuid|exists:requerimientos_documentos,id',
        'leida' => 'sometimes|boolean',
        'fecha_lectura' => 'nullable|date',
    ];
}
