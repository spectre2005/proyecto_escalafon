<?php

namespace Database\Factories;

use App\Models\Controversia;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Controversia> */
class ControversiaFactory extends Factory
{
    protected $model = Controversia::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'tipo' => substr(fake()->word(), 0, 15),
            'instancia' => substr(fake()->word(), 0, 25),
            'materia' => substr(fake()->word(), 0, 300),
            'fecha_inicio' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
