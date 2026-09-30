<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CertificadoDiscapacidad;

class CertificadoDiscapacidadController extends ResourceController
{
    protected string $model = CertificadoDiscapacidad::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'persona' => 'required|string|max:10',
        'familiar_id' => 'nullable|uuid|exists:familiares,id',
        'numero_conadis' => 'required|string|max:30',
        'fecha_emision' => 'nullable|date',
        'vigente_hasta' => 'nullable|date',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
