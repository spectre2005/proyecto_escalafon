<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ReferenciaLegajoPasivo;

class ReferenciaLegajoPasivoController extends ResourceController
{
    protected string $model = ReferenciaLegajoPasivo::class;

    protected array $rules = [
        'legajo_id' => 'required|uuid|exists:legajos,id',
        'vinculo_laboral_id' => 'required|uuid|exists:vinculos_laborales,id',
        'tipo_documento_id' => 'required|uuid|exists:tipos_documento,id',
        'folio_inicio_pasivo' => 'required|integer|between:-2147483648,2147483647',
        'folio_fin_pasivo' => 'required|integer|between:-2147483648,2147483647',
        'fedateado' => 'sometimes|boolean',
        'fecha_fedateo' => 'nullable|date',
        'fiscalizado' => 'sometimes|boolean',
        'fecha_fiscalizacion' => 'nullable|date',
        'responsable_legajo_id' => 'required|uuid|exists:usuarios,id',
    ];
}
