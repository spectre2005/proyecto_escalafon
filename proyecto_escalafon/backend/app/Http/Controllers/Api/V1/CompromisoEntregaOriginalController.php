<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CompromisoEntregaOriginal;

class CompromisoEntregaOriginalController extends ResourceController
{
    protected string $model = CompromisoEntregaOriginal::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'legajo_id' => 'required|uuid|exists:legajos,id',
        'motivo_impedimento' => 'required|string',
        'fecha_compromiso' => 'required|date',
        'fecha_limite' => 'required|date',
        'estado' => 'sometimes|string|max:15',
        'fecha_cumplimiento' => 'nullable|date',
        'archivo_id' => 'nullable|uuid|exists:archivos_documento,id',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
    ];
}
