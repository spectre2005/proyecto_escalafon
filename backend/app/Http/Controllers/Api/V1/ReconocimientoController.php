<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Reconocimiento;

class ReconocimientoController extends ResourceController
{
    protected string $model = Reconocimiento::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'tipo' => 'required|string|max:20',
        'fecha' => 'required|date',
        'motivo' => 'required|string|max:300',
        'otorgado_por' => 'nullable|string|max:150',
        'numero_documento' => 'nullable|string|max:60',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
