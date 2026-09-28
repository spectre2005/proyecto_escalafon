<?php

namespace Database\Factories;

use App\Models\Familiar;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Familiar> */
class FamiliarFactory extends Factory
{
    protected $model = Familiar::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'parentesco' => substr(fake()->word(), 0, 15),
            'apellidos_nombres' => substr(fake()->word(), 0, 200),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
