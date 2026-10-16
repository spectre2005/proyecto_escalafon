<?php

namespace Database\Factories;

use App\Models\SeccionLegajo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SeccionLegajo> */
class SeccionLegajoFactory extends Factory
{
    protected $model = SeccionLegajo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->numberBetween(14, 32767),
            'nombre' => substr(fake()->word(), 0, 150),
            'orden' => 1,
        ];
    }
}
