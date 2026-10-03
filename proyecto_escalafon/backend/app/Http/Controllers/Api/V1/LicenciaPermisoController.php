<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\LicenciaPermiso;

class LicenciaPermisoController extends ResourceController
{
    protected string $model = LicenciaPermiso::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'tipo' => 'required|string|max:30',
        'con_goce' => 'sometimes|boolean',
        'fecha_inicio' => 'required|date',
        'fecha_fin' => 'required|date',
        'dias' => 'required|integer|between:-2147483648,2147483647',
        'numero_documento' => 'nullable|string|max:60',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
