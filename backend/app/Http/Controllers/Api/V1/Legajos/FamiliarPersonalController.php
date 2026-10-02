<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Api\V1\ResourceController;
use App\Models\Familiar;
use App\Models\Usuario;
use App\Support\Catalogos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;

/**
 * Familiares desde la ficha (sección 01). Se registran y se corrigen; no se eliminan:
 * se anulan con motivo desde Seccion01Controller.
 */
class FamiliarPersonalController extends ResourceController
{
    protected string $model = Familiar::class;

    protected array $rules = [
        'apellidos_nombres' => 'required|string|max:200',
        'parentesco' => 'required|string',          // + lista cerrada en el constructor
        'tipo_doc_identidad' => 'nullable|string',  // + lista cerrada en el constructor
        'numero_doc_identidad' => 'nullable|string|max:20|required_with:tipo_doc_identidad',
        'fecha_nacimiento' => 'nullable|date|before_or_equal:today',
        'es_derechohabiente' => 'required|boolean',
    ];

    public function __construct()
    {
        $this->rules['parentesco'] .= '|'.Catalogos::in(Catalogos::PARENTESCOS);
        $this->rules['tipo_doc_identidad'] .= '|'.Catalogos::in(Catalogos::TIPOS_DOC_IDENTIDAD);
    }

    public function storeForPerson(Request $request, string $usuario): JsonResponse
    {
        Usuario::query()->findOrFail($usuario);
        $data = $this->validatedData($request);
        $record = Familiar::query()->create([...$data, 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);

        return (new JsonResource($record))->response()->setStatusCode(201);
    }

    public function updateForPerson(Request $request, string $usuario, string $familiar): JsonResource|JsonResponse
    {
        $record = Familiar::query()->where('usuario_id', $usuario)->findOrFail($familiar);
        if ($record->anulado) {
            return response()->json(['message' => 'Un familiar anulado no se puede editar.'], 409);
        }

        return parent::update($request, $familiar);
    }

    protected function validatedData(Request $request, bool $updating = false): array
    {
        $data = parent::validatedData($request, $updating);
        if (($data['tipo_doc_identidad'] ?? null) === 'dni' && ! preg_match('/^[0-9]{8}$/', (string) ($data['numero_doc_identidad'] ?? ''))) {
            throw ValidationException::withMessages(['numero_doc_identidad' => 'El DNI debe contener 8 dígitos.']);
        }

        return $data;
    }
}
