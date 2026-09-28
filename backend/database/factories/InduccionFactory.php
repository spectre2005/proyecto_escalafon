<?php

namespace Database\Factories;

use App\Models\Induccion;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Induccion> */
class InduccionFactory extends Factory
{
    protected $model = Induccion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'vinculo_laboral_id' => VinculoLaboral::factory(),
            'tipo' => substr(fake()->word(), 0, 15),
            'fecha' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
