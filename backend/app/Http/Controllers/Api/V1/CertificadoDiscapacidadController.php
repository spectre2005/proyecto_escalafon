<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CertificadoDiscapacidad;
use App\Support\CondicionesRegistro;
use Illuminate\Database\Eloquent\Model;

class CertificadoDiscapacidadController extends RegistroAnulableController
{
    protected string $model = CertificadoDiscapacidad::class;

    protected string $orden = 'created_at';

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'persona' => 'required|string|max:10',
        'familiar_id' => 'nullable|uuid|exists:familiares,id',
        'numero_conadis' => 'required|string|max:30',
        'fecha_emision' => 'nullable|date',
        'vigente_hasta' => 'nullable|date',
    ];

    /** Sin certificados nuevos con «Con discapacidad» en No (CondicionesRegistro). Solo se crean: no se editan. */
    protected function exigirCondiciones(array $data, ?Model $actual = null): void
    {
        if (! $actual) {
            CondicionesRegistro::certificado((string) $data['usuario_id']);
        }
    }
}
