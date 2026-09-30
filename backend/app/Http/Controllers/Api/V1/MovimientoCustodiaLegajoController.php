<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MovimientoCustodiaLegajo;

class MovimientoCustodiaLegajoController extends ResourceController
{
    protected string $model = MovimientoCustodiaLegajo::class;

    protected array $rules = [
        'legajo_id' => 'required|uuid|exists:legajos,id',
        'tipo_movimiento' => 'required|string|max:35',
        'base_autorizacion' => 'nullable|string|max:30',
        'autorizado_por' => 'required|uuid|exists:usuarios,id',
        'entidad_destino' => 'nullable|string|max:200',
        'numero_documento_sustento' => 'nullable|string|max:60',
        'sustento_archivo_id' => 'nullable|uuid|exists:archivos_documento,id',
        'formato_legajo' => 'nullable|string|max:10',
        'numero_inventario' => 'nullable|string|max:40',
        'fecha_movimiento' => 'required|date',
        'fecha_retorno' => 'nullable|date',
        'servidor_comunicado' => 'sometimes|boolean',
        'fecha_comunicacion_servidor' => 'nullable|date',
        'observaciones' => 'nullable|string',
    ];
}
