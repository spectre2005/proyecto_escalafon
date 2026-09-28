<?php

namespace Database\Factories;

use App\Models\AuditoriaEvento;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditoriaEvento> */
class AuditoriaEventoFactory extends Factory
{
    protected $model = AuditoriaEvento::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'accion' => substr(fake()->word(), 0, 40),
            'entidad' => substr(fake()->word(), 0, 60),
            'entidad_id' => fake()->uuid(),
            'hash_registro' => hash('sha256', fake()->uuid()),
        ];
    }
}
