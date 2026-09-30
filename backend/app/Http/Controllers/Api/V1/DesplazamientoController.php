<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Desplazamiento;

class DesplazamientoController extends ResourceController
{
    protected string $model = Desplazamiento::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'tipo' => 'required|string|max:20',
        'unidad_origen_id' => 'nullable|uuid|exists:unidades_organicas,id',
        'unidad_destino_id' => 'nullable|uuid|exists:unidades_organicas,id',
        'puesto_destino' => 'nullable|string|max:150',
        'fecha_inicio' => 'required|date',
        'fecha_fin' => 'nullable|date',
        'numero_documento' => 'nullable|string|max:60',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
