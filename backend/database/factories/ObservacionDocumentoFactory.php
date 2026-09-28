<?php

namespace Database\Factories;

use App\Models\DocumentoUsuario;
use App\Models\ObservacionDocumento;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ObservacionDocumento> */
class ObservacionDocumentoFactory extends Factory
{
    protected $model = ObservacionDocumento::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'documento_id' => DocumentoUsuario::factory(),
            'tipo_observacion' => substr(fake()->word(), 0, 30),
            'descripcion' => fake()->sentence(),
            'registrada_por' => Usuario::factory(),
            'plazo_subsanacion' => now(),
        ];
    }
}
