<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PerfilDocente;

class PerfilDocenteController extends ResourceController
{
    protected string $model = PerfilDocente::class;

    protected array $rules = [
        'vinculo_laboral_id' => 'required|uuid|exists:vinculos_laborales,id',
        'tipo_personal' => 'sometimes|string|max:15',
        'condicion_docente' => 'required|string|max:15',
        'categoria' => 'nullable|string|max:10',
        'dedicacion' => 'nullable|string|max:25',
        'subtipo_extraordinario' => 'nullable|string|max:15',
        'funcion_previa' => 'nullable|string|max:25',
        'codigo_renacyt' => 'nullable|string|max:30',
    ];
}
