<?php

namespace Database\Factories;

use App\Models\ProcesoSeleccion;
use App\Models\RegimenNormativo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcesoSeleccion>
 */
class ProcesoSeleccionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'regimen_normativo_id' => RegimenNormativo::factory(),
            'codigo' => fake()->unique()->numerify('CAS-###-2026'),
            'anio' => 2026,
        ];
    }
}
