<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BitacoraAccesoLegajo;

class BitacoraAccesoLegajoController extends ResourceController
{
    protected string $model = BitacoraAccesoLegajo::class;

    protected array $rules = [
        'legajo_id' => 'required|uuid|exists:legajos,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'solicitud_acceso_id' => 'nullable|uuid|exists:solicitudes_acceso_legajo,id',
        'accion' => 'required|string|max:15',
        'ip_origen' => 'nullable|string|max:45',
        'user_agent' => 'nullable|string|max:300',
        'fecha_acceso' => 'nullable|date',
    ];
}
