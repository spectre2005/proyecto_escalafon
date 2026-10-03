<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ArchivoDocumento;

class ArchivoDocumentoController extends ResourceController
{
    protected string $model = ArchivoDocumento::class;

    protected array $rules = [
        'almacenamiento' => 'sometimes|string|max:30',
        'bucket' => 'nullable|string|max:100',
        'storage_path' => 'required|string|max:500',
        'nombre_original' => 'required|string|max:255',
        'mime_type' => 'sometimes|string|max:100',
        'tamano_bytes' => 'required|integer',
        'hash_sha256' => 'required|string|max:64',
        'num_paginas' => 'nullable|integer|between:-2147483648,2147483647',
        'firmado_digitalmente' => 'sometimes|boolean',
        'cifrado' => 'sometimes|boolean',
        'estado_antivirus' => 'sometimes|string|max:15',
        'subido_por' => 'required|uuid|exists:usuarios,id',
        'subido_en' => 'nullable|date',
    ];
}
