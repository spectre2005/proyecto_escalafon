<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CompromisoDocumento;

class CompromisoDocumentoController extends ResourceController
{
    protected string $model = CompromisoDocumento::class;

    protected array $rules = [
        'compromiso_id' => 'required|uuid|exists:compromisos_entrega_originales,id',
        'tipo_documento_id' => 'required|uuid|exists:tipos_documento,id',
        'descripcion' => 'nullable|string|max:200',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'entregado_en' => 'nullable|date',
    ];
}
