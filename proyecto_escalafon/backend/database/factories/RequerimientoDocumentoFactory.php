<?php

namespace Database\Factories;

use App\Models\RequerimientoDocumento;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RequerimientoDocumento> */
class RequerimientoDocumentoFactory extends Factory
{
    protected $model = RequerimientoDocumento::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'origen' => substr(fake()->word(), 0, 15),
            'descripcion' => substr(fake()->word(), 0, 300),
            'fecha_requerimiento' => now(),
            'plazo_dias_habiles' => 1,
            'fecha_limite' => now(),
            'solicitado_por' => Usuario::factory(),
        ];
    }
}
