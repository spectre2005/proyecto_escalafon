<?php

namespace Database\Factories;

use App\Models\ProduccionIntelectual;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProduccionIntelectual> */
class ProduccionIntelectualFactory extends Factory
{
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'tipo' => 'articulo', 'titulo' => substr(fake()->sentence(), 0, 300),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
