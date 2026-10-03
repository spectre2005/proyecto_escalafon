<?php

namespace Database\Factories;

use App\Models\UnidadOrganica;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UnidadOrganica> */
class UnidadOrganicaFactory extends Factory
{
    protected $model = UnidadOrganica::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'codigo' => substr(fake()->word(), 0, 20),
            'nombre' => substr(fake()->word(), 0, 150),
            'tipo' => substr(fake()->word(), 0, 30),
        ];
    }
}
