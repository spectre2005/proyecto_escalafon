<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FormacionAcademica;
use App\Support\CondicionesRegistro;
use Illuminate\Database\Eloquent\Model;

class FormacionAcademicaController extends ResourceController
{
    protected string $model = FormacionAcademica::class;

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'nivel' => 'required|string|max:30',
        'denominacion' => 'required|string|max:200',
        'institucion' => 'required|string|max:200',
        'pais' => 'sometimes|string|max:60',
        'es_revalidado' => 'sometimes|boolean',
        'fecha_inicio' => 'nullable|date',
        'fecha_obtencion' => 'nullable|date',
        'numero_registro_sunedu' => 'nullable|string|max:40',
        'numero_diploma' => 'nullable|string|max:40',
        'registrado_por' => 'required|uuid|exists:usuarios,id',
        'anulado' => 'sometimes|boolean',
        'motivo_anulacion' => 'nullable|string',
    ];

    /** Sin estudios básicos nuevos con «El puesto exige estudios básicos» en No (CondicionesRegistro). */
    protected function exigirCondiciones(array $data, ?Model $actual = null): void
    {
        if (($data['nivel'] ?? null) === 'educacion_basica' && $actual?->nivel !== 'educacion_basica') {
            CondicionesRegistro::formacion((string) ($data['usuario_id'] ?? $actual?->usuario_id), 'educacion_basica');
        }
    }
}
