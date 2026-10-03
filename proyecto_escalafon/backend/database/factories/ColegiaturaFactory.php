<?php

namespace Database\Factories;

use App\Models\Colegiatura;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Colegiatura> */
class ColegiaturaFactory extends Factory
{
    protected $model = Colegiatura::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'colegio_profesional' => substr(fake()->word(), 0, 150),
            'numero_colegiatura' => substr(fake()->word(), 0, 30),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
