<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CompensacionBeneficio;
use App\Models\DocumentoUsuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompensacionBeneficioController extends ResourceController
{
    protected string $model = CompensacionBeneficio::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'tipo' => 'required|string|max:15',
        'concepto' => 'required|string|max:200',
        'fecha' => 'required|date',
        'numero_documento' => 'nullable|string|max:60',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];

    protected function indexQuery(): Builder
    {
        $query = parent::indexQuery();
        if (! request()->user()->veDatosSensibles()) {
            $query->whereHas('documento', fn ($doc) => $doc->whereNotIn('seccion_legajo_id', [1, 9, 10])->whereHas('tipoDocumento', fn ($tipo) => $tipo->where('es_dato_sensible', false)->where('es_dato_salud', false)));
        }

        return $query;
    }

    public function show(string $record): JsonResource
    {
        return new JsonResource($this->indexQuery()->findOrFail($record));
    }

    public function store(Request $request): JsonResponse
    {
        $this->protegerDestino($request);

        return parent::store($request);
    }

    public function update(Request $request, string $record): JsonResource|JsonResponse
    {
        $this->protegerRegistro($record);
        $this->protegerDestino($request);

        return parent::update($request, $record);
    }

    public function destroy(string $record): JsonResponse
    {
        $this->protegerRegistro($record);

        return parent::destroy($record);
    }

    private function protegerRegistro(string $record): void
    {
        $registro = $this->indexQuery()->with('documento')->findOrFail($record);
        abort_if($registro->documento?->seccion_legajo_id === 6, 409, 'Este registro está sustentado en el legajo. Corrígelo mediante un documento que subsane al anterior.');
    }

    private function protegerDestino(Request $request): void
    {
        $datos = $request->validate(['documento_id' => 'nullable|uuid']);
        if (! empty($datos['documento_id'])) {
            abort_if(DocumentoUsuario::query()->whereKey($datos['documento_id'])->where('seccion_legajo_id', 6)->exists(), 409,
                'Registra los datos junto con su documento en la Sección 06.');
        }
    }
}
