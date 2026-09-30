<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\RatificacionDocente;

class RatificacionDocenteController extends ResourceController
{
    protected string $model = RatificacionDocente::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'perfil_docente_id' => 'required|uuid|exists:perfil_docente,id',
        'tipo' => 'required|string|max:15',
        'categoria_anterior' => 'nullable|string|max:10',
        'categoria_nueva' => 'required|string|max:10',
        'numero_resolucion' => 'required|string|max:60',
        'fecha_resolucion' => 'required|date',
        'fecha_inicio_periodo' => 'required|date',
        'fecha_vencimiento_periodo' => 'required|date',
        'resultado' => 'sometimes|string|max:15',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
