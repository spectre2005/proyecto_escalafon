<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Familiar;
use Illuminate\Http\Request;

class FamiliarController extends ResourceController
{
    protected string $model = Familiar::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'parentesco' => 'required|string|max:15',
        'tipo_doc_identidad' => 'nullable|string|max:15',
        'numero_doc_identidad' => 'nullable|string|max:20',
        'apellidos_nombres' => 'required|string|max:200',
        'fecha_nacimiento' => 'nullable|date',
        'es_derechohabiente' => 'sometimes|boolean',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];

    protected function validatedData(Request $request, bool $updating = false): array
    {
        $data = parent::validatedData($request, $updating);
        if (! $updating) {
            $data['registrado_por'] = $request->user()->getAuthIdentifier();
        }

        return $data;
    }
}
