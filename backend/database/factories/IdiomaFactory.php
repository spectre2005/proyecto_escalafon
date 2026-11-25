<?php

namespace Database\Factories;

use App\Models\Idioma;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Idioma> */
class IdiomaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'idioma' => 'Inglés', 'tipo' => 'extranjero', 'nivel' => 'intermedio',
            'registrado_por' => Usuario::factory(),
        ];
    }
}
