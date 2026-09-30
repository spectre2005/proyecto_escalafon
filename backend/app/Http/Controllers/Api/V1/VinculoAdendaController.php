<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\VinculoAdenda;

class VinculoAdendaController extends ResourceController
{
    protected string $model = VinculoAdenda::class;

    protected array $rules = [
        'vinculo_laboral_id' => 'required|uuid|exists:vinculos_laborales,id',
        'numero' => 'nullable|string|max:40',
        'motivo' => 'nullable|string|max:200',
        'fecha_inicio' => 'required|date',
        'fecha_fin' => 'required|date',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
    ];
}
