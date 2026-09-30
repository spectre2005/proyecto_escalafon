<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\RequerimientoDocumento;

class RequerimientoDocumentoController extends ResourceController
{
    protected string $model = RequerimientoDocumento::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'tipo_documento_id' => 'nullable|uuid|exists:tipos_documento,id',
        'origen' => 'required|string|max:15',
        'descripcion' => 'required|string|max:300',
        'fecha_requerimiento' => 'required|date',
        'plazo_dias_habiles' => 'required|integer|between:-2147483648,2147483647',
        'fecha_limite' => 'required|date',
        'prorroga_hasta' => 'nullable|date',
        'estado' => 'sometimes|string|max:15',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'solicitado_por' => 'required|uuid|exists:usuarios,id',
        'cerrado_en' => 'nullable|date',
    ];
}
