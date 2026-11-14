<?php

namespace Database\Factories;

use App\Models\TipoProcesoSeleccion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TipoProcesoSeleccion> */
class TipoProcesoSeleccionFactory extends Factory
{
    protected $model = TipoProcesoSeleccion::class;

    public function definition(): array
    {
        return [
            'codigo' => 'PS-'.fake()->unique()->numerify('9###'),
            'nombre' => 'Proceso '.fake()->unique()->word(),
            'activo' => true,
        ];
    }
}
