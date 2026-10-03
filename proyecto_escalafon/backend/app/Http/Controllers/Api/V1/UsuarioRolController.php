<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UsuarioRol;

class UsuarioRolController extends ResourceController
{
    protected string $model = UsuarioRol::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'rol_id' => 'required|uuid|exists:roles,id',
        'ambito_tipo_personal' => 'sometimes|string|max:15',
        'unidad_organica_id' => 'nullable|uuid|exists:unidades_organicas,id',
        'vigente_desde' => 'required|date',
        'vigente_hasta' => 'nullable|date',
        'asignado_por' => 'required|uuid|exists:usuarios,id',
    ];
}
