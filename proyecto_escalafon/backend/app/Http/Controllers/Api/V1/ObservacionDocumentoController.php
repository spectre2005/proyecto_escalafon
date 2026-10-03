<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ObservacionDocumento;

class ObservacionDocumentoController extends ResourceController
{
    protected string $model = ObservacionDocumento::class;

    protected array $rules = [
        'documento_id' => 'required|uuid|exists:documentos_usuario,id',
        'tipo_observacion' => 'required|string|max:30',
        'descripcion' => 'required|string',
        'estado' => 'sometimes|string|max:15',
        'registrada_por' => 'required|uuid|exists:usuarios,id',
        'fecha_registro' => 'nullable|date',
        'plazo_subsanacion' => 'required|date',
        'prorroga_hasta' => 'nullable|date',
        'documento_subsanacion_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'fecha_subsanacion' => 'nullable|date',
        'verificada_por' => 'nullable|uuid|exists:usuarios,id',
        'comentario_cierre' => 'nullable|string',
    ];
}
