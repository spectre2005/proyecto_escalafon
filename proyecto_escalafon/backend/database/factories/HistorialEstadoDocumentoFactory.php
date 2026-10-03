<?php

namespace Database\Factories;

use App\Models\DocumentoUsuario;
use App\Models\HistorialEstadoDocumento;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HistorialEstadoDocumento> */
class HistorialEstadoDocumentoFactory extends Factory
{
    protected $model = HistorialEstadoDocumento::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'documento_usuario_id' => DocumentoUsuario::factory(),
            'estado_nuevo' => substr(fake()->word(), 0, 20),
            'cambiado_por' => Usuario::factory(),
        ];
    }
}
