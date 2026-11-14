<?php

namespace Database\Factories;

use App\Models\TipoVinculo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TipoVinculo> */
class TipoVinculoFactory extends Factory
{
    protected $model = TipoVinculo::class;

    public function definition(): array
    {
        return [
            'codigo' => 'tipo_'.fake()->unique()->numerify('####'),
            'nombre' => 'Tipo '.fake()->unique()->word(),
            'orden' => 9,
        ];
    }
}
