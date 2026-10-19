<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Api\V1\ResourceController;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Usuario;
use App\Support\Auditoria;
use App\Support\Catalogos;
use App\Support\CondicionesRegistro;
use App\Support\Documentos\FormulariosDatos;
use App\Support\Seccion01;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Familiares desde la ficha (sección 01). Se registran y se corrigen; no se eliminan:
 * se anulan con motivo desde Seccion01Controller. Al registrarlo se puede enlazar al documento recién subido
 * que lo acredita (i: DNI del cónyuge o concubino/a; j: DNI del hijo/a); el enlace no cambia después.
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
        'documento_id' => 'nullable|uuid',
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
        CondicionesRegistro::familiar($usuario, (bool) ($data['es_derechohabiente'] ?? false));
        $record = new Familiar([...collect($data)->except('documento_id')->all(), 'usuario_id' => $usuario, 'registrado_por' => $request->user()->getAuthIdentifier()]);

        $creado = DB::transaction(function () use ($usuario, $data, $record, $request): Model {
            $creado = FormulariosDatos::crearEnlazado('familiares', $usuario, $data['documento_id'] ?? null, $record);
            Auditoria::registrar($request, 'familiar.registrar', 'familiares', $creado->getKey(), $usuario, null, $creado->getAttributes());

            return $creado;
        });

        return (new JsonResource($creado))->response()->setStatusCode(201);
    }

    public function updateForPerson(Request $request, string $usuario, string $familiar): JsonResource|JsonResponse
    {
        $record = Familiar::query()->where('usuario_id', $usuario)->findOrFail($familiar);
        if ($record->anulado) {
            return response()->json(['message' => 'Un familiar anulado no se puede editar.'], 409);
        }
        if ($request->has('documento_id')) {
            throw ValidationException::withMessages(['documento_id' => 'El documento se enlaza al registrar el familiar o al subirlo; no se cambia después.']);
        }
        // Enlazado a un documento (i o j): el parentesco debe seguir correspondiendo al tipo de ese documento.
        if ($record->documento_id && $request->has('parentesco')) {
            $tipo = DocumentoUsuario::query()->with('tipoDocumento')->findOrFail($record->documento_id)->tipoDocumento;
            if (! Seccion01::compatible($tipo, new Familiar(['parentesco' => $request->input('parentesco')]))) {
                throw ValidationException::withMessages(['parentesco' => 'El familiar está enlazado a un documento de otro parentesco: no se puede cambiar a este.']);
            }
        }

        return DB::transaction(function () use ($request, $familiar, $record, $usuario): JsonResource|JsonResponse {
            $antes = $record->getAttributes();
            $respuesta = parent::update($request, $familiar);
            [$previo, $nuevo] = Auditoria::diferencia(Arr::except($antes, ['updated_at']), Arr::except($record->refresh()->getAttributes(), ['updated_at']));
            if ($nuevo !== []) {
                Auditoria::registrar($request, 'familiar.corregir', 'familiares', $record->getKey(), $usuario, $previo, $nuevo);
            }

            return $respuesta;
        });
    }

    protected function validatedData(Request $request, bool $updating = false): array
    {
        $data = parent::validatedData($request, $updating);
        if (($data['tipo_doc_identidad'] ?? null) === 'dni' && ! preg_match('/^[0-9]{8}$/', (string) ($data['numero_doc_identidad'] ?? ''))) {
            throw ValidationException::withMessages(['numero_doc_identidad' => 'El DNI debe contener 8 dígitos.']);
        }

        return $data;
    }

    /** Un familiar derechohabiente no se registra con «Tiene derechohabientes» en No (CondicionesRegistro). */
    protected function exigirCondiciones(array $data, ?Model $actual = null): void
    {
        if (($data['es_derechohabiente'] ?? false) && ! $actual?->es_derechohabiente) {
            CondicionesRegistro::familiar((string) ($data['usuario_id'] ?? $actual?->usuario_id), true);
        }
    }
}
