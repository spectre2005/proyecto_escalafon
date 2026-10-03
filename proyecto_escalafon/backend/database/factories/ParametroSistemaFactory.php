<?php

namespace Database\Factories;

use App\Models\ParametroSistema;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ParametroSistema> */
class ParametroSistemaFactory extends Factory
{
    protected $model = ParametroSistema::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'clave' => substr(fake()->word(), 0, 80),
            'valor' => substr(fake()->word(), 0, 200),
            'tipo_dato' => substr(fake()->word(), 0, 10),
        ];
    }
}
