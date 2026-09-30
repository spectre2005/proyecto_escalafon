<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TiempoServicioReconocido;

class TiempoServicioReconocidoController extends ResourceController
{
    protected string $model = TiempoServicioReconocido::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'numero_resolucion' => 'required|string|max:60',
        'fecha_resolucion' => 'required|date',
        'anios' => 'sometimes|integer|between:-2147483648,2147483647',
        'meses' => 'sometimes|integer|between:-2147483648,2147483647',
        'dias' => 'sometimes|integer|between:-2147483648,2147483647',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
