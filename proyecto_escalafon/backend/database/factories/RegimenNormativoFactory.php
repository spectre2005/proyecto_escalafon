<?php

namespace Database\Factories;

use App\Models\RegimenNormativo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegimenNormativo> */
class RegimenNormativoFactory extends Factory
{
    protected $model = RegimenNormativo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'codigo' => substr(fake()->word(), 0, 20),
            'nombre' => substr(fake()->word(), 0, 150),
            'aplica_a' => substr(fake()->word(), 0, 15),
        ];
    }
}
