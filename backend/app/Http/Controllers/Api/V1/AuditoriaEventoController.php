<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditoriaEvento;

class AuditoriaEventoController extends ResourceController
{
    protected string $model = AuditoriaEvento::class;

    protected array $rules = [
        'fecha_evento' => 'nullable|date',
        'actor_usuario_id' => 'nullable|uuid|exists:usuarios,id',
        'usuario_titular_id' => 'nullable|uuid|exists:usuarios,id',
        'accion' => 'required|string|max:40',
        'entidad' => 'required|string|max:60',
        'entidad_id' => 'required|uuid',
        'datos_antes' => 'nullable|string',
        'datos_despues' => 'nullable|string',
        'ip_origen' => 'nullable|string|max:45',
        'user_agent' => 'nullable|string|max:300',
        'request_id' => 'nullable|uuid',
        'hash_previo' => 'nullable|string|max:64',
        'hash_registro' => 'required|string|max:64',
    ];
}
