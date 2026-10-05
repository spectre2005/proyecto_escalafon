<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DocumentoUsuario;
use App\Models\ExperienciaLaboral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExperienciaLaboralController extends ResourceController
{
    protected string $model = ExperienciaLaboral::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'entidad_empresa' => 'required|string|max:200',
        'sector' => 'required|string|max:10',
        'cargo' => 'required|string|max:150',
        'funciones' => 'nullable|string',
        'fecha_inicio' => 'required|date',
        'fecha_fin' => 'nullable|date',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];

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
        $periodo = ExperienciaLaboral::query()->with('documento')->findOrFail($record);
        abort_if($periodo->documento?->seccion_legajo_id === 4, 409, 'Esta experiencia está sustentada en el legajo. Para corregirla, agrega un documento que subsane al anterior.');
    }

    private function protegerDestino(Request $request): void
    {
        $datos = $request->validate(['documento_id' => 'nullable|uuid']);
        if (! empty($datos['documento_id'])) {
            abort_if(DocumentoUsuario::query()->whereKey($datos['documento_id'])->where('seccion_legajo_id', 4)->exists(), 409,
                'Registra esta experiencia mediante Agregar documento en la Sección 04.');
        }
    }
}
