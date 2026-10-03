<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PerfilAdministrativo;

class PerfilAdministrativoController extends ResourceController
{
    protected string $model = PerfilAdministrativo::class;

    protected array $rules = [
        'vinculo_laboral_id' => 'required|uuid|exists:vinculos_laborales,id',
        'tipo_personal' => 'sometimes|string|max:15',
        'grupo_ocupacional' => 'nullable|string|max:15',
        'nivel_carrera' => 'nullable|string|max:20',
        'cargo_estructural' => 'nullable|string|max:150',
        'es_cargo_confianza' => 'sometimes|boolean',
    ];
}
