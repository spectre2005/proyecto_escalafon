<?php

namespace Database\Factories;

use App\Models\SeccionLegajo;
use App\Models\SubseccionLegajo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubseccionLegajo> */
class SubseccionLegajoFactory extends Factory
{
    protected $model = SubseccionLegajo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'seccion_legajo_id' => SeccionLegajo::factory(),
            'codigo' => substr(fake()->word(), 0, 10),
            'nombre' => substr(fake()->word(), 0, 150),
        ];
    }
}
