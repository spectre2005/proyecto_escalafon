<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DocumentoUsuario;

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
}
