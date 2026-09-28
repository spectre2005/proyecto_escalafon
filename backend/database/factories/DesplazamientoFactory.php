<?php

namespace Database\Factories;

use App\Models\Desplazamiento;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Desplazamiento> */
class DesplazamientoFactory extends Factory
{
    protected $model = Desplazamiento::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'tipo' => substr(fake()->word(), 0, 20),
            'fecha_inicio' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
