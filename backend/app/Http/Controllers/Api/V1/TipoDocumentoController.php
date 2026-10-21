<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TipoDocumento;

class TipoDocumentoController extends ResourceController
{
    protected string $model = TipoDocumento::class;

    protected array $rules = [
        'seccion_legajo_id' => 'required|integer|between:-32768,32767|exists:secciones_legajo,id',
        'subseccion_legajo_id' => 'nullable|uuid|exists:subsecciones_legajo,id',
        'codigo' => 'required|string|max:40',
        'nombre' => 'required|string|max:200',
        'descripcion' => 'nullable|string',
        'base_normativa' => 'sometimes|string|max:15',
        'aplica_carrera' => 'sometimes|boolean',
        'aplica_plazo_determinado' => 'sometimes|boolean',
        'aplica_plazo_indeterminado' => 'sometimes|boolean',
        'aplica_designado' => 'sometimes|boolean',
        'aplica_tipo_personal' => 'sometimes|in:ambos,docente,administrativo',
        'requiere_docente' => 'sometimes|boolean',
        'requiere_administrativo' => 'sometimes|boolean',
        'requiere_djbr' => 'sometimes|boolean',
        'requiere_colegiatura' => 'sometimes|boolean',
        'requiere_derechohabientes' => 'sometimes|boolean',
        'requiere_periodo_prueba' => 'sometimes|boolean',
        'requiere_discapacidad' => 'sometimes|boolean',
        'tiene_banderas' => 'sometimes|boolean',
        'es_universal' => 'sometimes|boolean',
        'obligatorio' => 'sometimes|boolean',
        'acredita_perfil_puesto' => 'sometimes|boolean',
        'permite_multiples' => 'sometimes|boolean',
        'requiere_num_resolucion' => 'sometimes|boolean',
        'requiere_fedateo' => 'sometimes|boolean',
        'es_dato_sensible' => 'sometimes|boolean',
        'es_dato_salud' => 'sometimes|boolean',
        'es_hook_universidad' => 'sometimes|boolean',
        'formulario_datos' => 'nullable|string|max:40',
        'orden' => 'sometimes|integer|between:-32768,32767',
        'activo' => 'sometimes|boolean',
    ];
}
