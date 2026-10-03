<?php

namespace Database\Factories;

use App\Models\Ubigeo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ubigeo> */
class UbigeoFactory extends Factory
{
    protected $model = Ubigeo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'region' => substr(fake()->word(), 0, 100),
            'provincia' => substr(fake()->word(), 0, 100),
            'distrito' => substr(fake()->word(), 0, 100),
        ];
    }
}
