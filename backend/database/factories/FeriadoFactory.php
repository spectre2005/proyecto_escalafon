<?php

namespace Database\Factories;

use App\Models\Feriado;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Feriado> */
class FeriadoFactory extends Factory
{
    protected $model = Feriado::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'fecha' => now(),
            'descripcion' => substr(fake()->word(), 0, 120),
        ];
    }
}
