<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DeclaracionVerificacion;
use App\Support\CondicionesRegistro;
use Illuminate\Database\Eloquent\Model;

class DeclaracionVerificacionController extends RegistroAnulableController
{
    protected string $model = DeclaracionVerificacion::class;

    protected string $orden = 'fecha';

    protected array $rules = [
        'usuario_id' => 'required|uuid|exists:usuarios,id',
        'documento_id' => 'nullable|uuid|exists:documentos_usuario,id',
        'tipo' => 'required|string|max:25',
        'anio' => 'nullable|integer|between:-2147483648,2147483647',
        'fecha' => 'required|date',
        'resultado' => 'required|string|max:15',
        'vigente_hasta' => 'nullable|date',
        'observacion' => 'nullable|string|max:300',
    ];

    /** Sin DJBR nueva con «Obligado a presentar declaración jurada de bienes y rentas» en No (CondicionesRegistro). */
    protected function exigirCondiciones(array $data, ?Model $actual = null): void
    {
        if (! $actual) {
            CondicionesRegistro::declaracion((string) $data['usuario_id'], $data['tipo'] ?? null);
        }
    }
}
