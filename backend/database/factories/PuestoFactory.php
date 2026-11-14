<?php

namespace Database\Factories;

use App\Models\Puesto;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Puesto> */
class PuestoFactory extends Factory
{
    protected $model = Puesto::class;

    public function definition(): array
    {
        return [
            'codigo' => 'PU-'.fake()->unique()->numerify('9###'),
            'nombre' => 'Puesto '.fake()->unique()->word(),
            'tipo_personal' => 'administrativo',
            'es_cargo_confianza' => false,
            'activo' => true,
        ];
    }
}
