<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Api\V1\ResourceController;
use App\Models\Familiar;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FamiliarPersonalController extends ResourceController
{
    protected string $model = Familiar::class;

    protected array $rules = [
        'apellidos_nombres' => 'required|string|max:200',
        'parentesco' => 'required|string|max:15',
        'tipo_doc_identidad' => 'nullable|string|max:15',
        'numero_doc_identidad' => 'nullable|string|max:20',
        'fecha_nacimiento' => 'nullable|date|before_or_equal:today',
        'es_derechohabiente' => 'required|boolean',
    ];

    public function storeForPerson(Request $request, string $usuario): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $data = $this->validatedData($request);
        $record = Familiar::query()->create([...$data, 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);

        return (new JsonResource($record))->response()->setStatusCode(201);
    }

    public function updateForPerson(Request $request, string $usuario, string $familiar): JsonResource|JsonResponse
    {
        Familiar::query()->where('usuario_id', $usuario)->findOrFail($familiar);

        return parent::update($request, $familiar);
    }

    public function destroyForPerson(string $usuario, string $familiar): JsonResponse
    {
        Familiar::query()->where('usuario_id', $usuario)->findOrFail($familiar);

        return parent::destroy($familiar);
    }
}
