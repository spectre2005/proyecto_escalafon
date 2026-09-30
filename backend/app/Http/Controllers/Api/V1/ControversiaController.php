<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Controversia;

class ControversiaController extends ResourceController
{
    protected string $model = Controversia::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'tipo' => 'required|string|max:15',
        'instancia' => 'required|string|max:25',
        'materia' => 'required|string|max:300',
        'fecha_inicio' => 'required|date',
        'estado' => 'sometimes|string|max:15',
        'fecha_resolucion' => 'nullable|date',
        'resultado' => 'nullable|string|max:300',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];
}
