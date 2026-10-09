<?php

namespace Database\Factories;

use App\Models\CicloVigencia;
use App\Models\RenovacionLegajo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RenovacionLegajo>
 */
class RenovacionLegajoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ciclo_id' => CicloVigencia::factory(), 'estado' => 'borrador',
        ];
    }
}
