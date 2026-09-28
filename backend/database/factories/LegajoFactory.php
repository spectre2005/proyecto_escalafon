<?php

namespace Database\Factories;

use App\Models\Legajo;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Legajo> */
class LegajoFactory extends Factory
{
    protected $model = Legajo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'numero_legajo' => substr(fake()->word(), 0, 20),
            'fecha_apertura' => now(),
        ];
    }
}
