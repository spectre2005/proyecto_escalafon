<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DocumentoUsuario;
use App\Models\TipoDocumento;
use App\Support\ResumenSeccion04;
use App\Support\ResumenSeccion06;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentoUsuarioController extends ResourceController
{
    protected string $model = DocumentoUsuario::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'legajo_id' => 'required|uuid|exists:legajos,id',
        'tipo_documento_id' => 'required|uuid|exists:tipos_documento,id',
        'seccion_legajo_id' => 'required|integer|between:-32768,32767|exists:secciones_legajo,id',
        'vinculo_laboral_id' => 'nullable|uuid|exists:vinculos_laborales,id',
        'archivo_id' => 'nullable|uuid|exists:archivos_documento,id',
        'modalidad_presentacion' => 'nullable|string|max:20',
        'motivo_incorporacion' => 'nullable|string|max:300',
        'folio_inicio' => 'nullable|integer|between:-2147483648,2147483647',
        'folio_fin' => 'nullable|integer|between:-2147483648,2147483647',
        'fecha_recepcion' => 'nullable|date',
        'recepcionado_por' => 'nullable|uuid|exists:usuarios,id',
        'fecha_incorporacion' => 'nullable|date',
        'emisor' => 'nullable|string|max:200',
        'fecha_emision' => 'nullable|date',
        'titular_nombre' => 'nullable|string|max:200',
        'titular_documento' => 'nullable|string|max:20',
        'relacion_titular' => 'sometimes|string|max:20',
        'num_resolucion' => 'nullable|string|max:60',
        'estado' => 'sometimes|string|max:20',
        'fedateado' => 'sometimes|boolean',
        'fecha_fedateo' => 'nullable|date',
        'fedatario_id' => 'nullable|uuid|exists:usuarios,id',
        'fiscalizado' => 'sometimes|boolean',
        'fecha_fiscalizacion' => 'nullable|date',
        'fiscalizado_por' => 'nullable|uuid|exists:usuarios,id',
        'subsana_a_documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'created_by' => 'required|uuid|exists:usuarios,id',
    ];

    protected function indexQuery(): Builder
    {
        $query = parent::indexQuery();
        if (! request()->user()->veDatosSensibles()) {
            $query->whereNotIn('seccion_legajo_id', [1, 9, 10])
                ->whereHas('tipoDocumento', fn ($tipo) => $tipo->where('es_dato_sensible', false)->where('es_dato_salud', false));
        }

        return $query;
    }

    public function show(string $record): JsonResource
    {
        return new JsonResource($this->indexQuery()->findOrFail($record));
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate(['tipo_documento_id' => 'required|uuid|exists:tipos_documento,id']);
        $tipo = TipoDocumento::query()->findOrFail($datos['tipo_documento_id']);
        abort_if($tipo->seccion_legajo_id === 6 && isset(ResumenSeccion06::ITEMS[$tipo->codigo]), 409, 'Registra esta evidencia mediante Agregar documento en la Sección 06.');
        abort_if($tipo->seccion_legajo_id === 4 && isset(ResumenSeccion04::ITEMS[$tipo->codigo]), 409,
            'Registra esta evidencia mediante Agregar documento en la Sección 04.');

        return parent::store($request);
    }
}
