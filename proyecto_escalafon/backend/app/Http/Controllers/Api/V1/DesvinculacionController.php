<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Desvinculacion;

class DesvinculacionController extends ResourceController
{
    protected string $model = Desvinculacion::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'vinculo_laboral_id' => 'required|uuid|exists:vinculos_laborales,id',
        'motivo_cese' => 'required|string|max:25',
        'fecha_cese' => 'required|date',
        'numero_resolucion' => 'nullable|string|max:60',
        'fecha_entrega_cargo' => 'nullable|date',
        'informe_gestion_presentado' => 'sometimes|boolean',
        'fecha_liquidacion' => 'nullable|date',
        'observaciones' => 'nullable|string',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
