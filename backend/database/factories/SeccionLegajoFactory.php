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
        // Ids de prueba desde 1000: los normativos (1 a 13) los crean las migraciones y los seeders de catálogo, y los tests
        // los usan por número. Se salta además cualquier id que ya exista en la tabla (smallint, máximo 32767).
        do {
            $id = fake()->unique()->numberBetween(1000, 32767);
        } while (SeccionLegajo::query()->whereKey($id)->exists());

        return [
            'id' => $id,
            'nombre' => substr(fake()->word(), 0, 150),
            'orden' => 1,
        ];
    }
}
