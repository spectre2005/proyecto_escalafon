<?php

namespace Database\Factories;

use App\Models\ProgresionCarrera;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProgresionCarrera> */
class ProgresionCarreraFactory extends Factory
{
    protected $model = ProgresionCarrera::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'vinculo_laboral_id' => VinculoLaboral::factory(),
            'nivel_nuevo' => substr(fake()->word(), 0, 20),
            'fecha_efectiva' => now(),
            'numero_resolucion' => substr(fake()->word(), 0, 60),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
