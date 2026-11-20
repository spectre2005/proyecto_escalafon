<?php

namespace Database\Factories;

use App\Models\Puesto;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Puesto> */
class PuestoFactory extends Factory
{
    protected $model = Puesto::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->jobTitle(),
            'tipo_personal' => fake()->randomElement(['docente', 'administrativo']),
            'activo' => true,
        ];
    }
}
