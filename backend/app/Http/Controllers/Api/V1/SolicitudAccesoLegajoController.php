<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SolicitudAccesoLegajo;

class SolicitudAccesoLegajoController extends ResourceController
{
    protected string $model = SolicitudAccesoLegajo::class;

    protected array $rules = [
        'legajo_id' => 'required|uuid|exists:legajos,id',
        'solicitante_usuario_id' => 'nullable|uuid|exists:usuarios,id',
        'solicitante_nombre' => 'required|string|max:200',
        'solicitante_documento' => 'required|string|max:20',
        'entidad_procedencia' => 'nullable|string|max:200',
        'tipo_solicitante' => 'required|string|max:20',
        'detalle_requerido' => 'required|string',
        'sustento' => 'required|string',
        'forma_entrega' => 'required|string|max:10',
        'incluye_datos_sensibles' => 'sometimes|boolean',
        'compromiso_confidencialidad' => 'sometimes|boolean',
        'estado' => 'sometimes|string|max:15',
        'resuelta_por' => 'nullable|uuid|exists:usuarios,id',
        'fecha_resolucion' => 'nullable|date',
        'motivo_resolucion' => 'nullable|string',
        'vigente_hasta' => 'nullable|date',
    ];
}
