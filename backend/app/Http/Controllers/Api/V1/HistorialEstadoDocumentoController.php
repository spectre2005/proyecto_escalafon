<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\HistorialEstadoDocumento;

class HistorialEstadoDocumentoController extends ResourceController
{
    protected string $model = HistorialEstadoDocumento::class;

    protected array $rules = [
        'documento_usuario_id' => 'required|uuid|exists:documentos_usuario,id',
        'estado_anterior' => 'nullable|string|max:20',
        'estado_nuevo' => 'required|string|max:20',
        'cambiado_por' => 'required|uuid|exists:usuarios,id',
        'motivo' => 'nullable|string',
        'observacion_id' => 'nullable|uuid|exists:observaciones_documento,id',
        'ip_origen' => 'nullable|string|max:45',
        'fecha_cambio' => 'nullable|date',
    ];
}
