<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\VinculoLaboral;

class VinculoLaboralController extends ResourceController
{
    protected string $model = VinculoLaboral::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'tipo_personal' => 'required|string|max:15',
        'regimen_laboral' => 'required|string|max:20',
        'regimen_normativo_id' => 'required|uuid|exists:regimenes_normativos,id',
        'unidad_organica_id' => 'nullable|uuid|exists:unidades_organicas,id',
        'nombre_puesto' => 'required|string|max:150',
        'obligado_djbr' => 'sometimes|boolean',
        'exige_colegiatura' => 'sometimes|boolean',
        'tiene_periodo_prueba' => 'sometimes|boolean',
        'proceso_seleccion' => 'nullable|string|max:100',
        'fecha_resultado_seleccion' => 'nullable|date',
        'numero_documento_vinculo' => 'nullable|string|max:60',
        'fecha_inicio' => 'required|date',
        'fecha_fin_prevista' => 'nullable|date',
        'fecha_cese' => 'nullable|date',
    ];
}
