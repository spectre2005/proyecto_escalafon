<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Sancion;

class SancionController extends ResourceController
{
    protected string $model = Sancion::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'tipo' => 'required|string|max:25',
        'numero_resolucion' => 'required|string|max:60',
        'fecha_resolucion' => 'required|date',
        'falta_imputada' => 'nullable|string|max:300',
        'fecha_inicio' => 'nullable|date',
        'fecha_fin' => 'nullable|date',
        'dias_suspension' => 'nullable|integer|between:-2147483648,2147483647',
        'resultado_recurso' => 'sometimes|string|max:15',
        'fecha_resultado_recurso' => 'nullable|date',
        'registrada_en_rnssc' => 'sometimes|boolean',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
