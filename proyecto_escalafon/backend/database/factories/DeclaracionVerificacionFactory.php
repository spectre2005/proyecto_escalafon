<?php

namespace Database\Factories;

use App\Models\DeclaracionVerificacion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeclaracionVerificacion> */
class DeclaracionVerificacionFactory extends Factory
{
    protected $model = DeclaracionVerificacion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'tipo' => substr(fake()->word(), 0, 25),
            'fecha' => now(),
            'resultado' => substr(fake()->word(), 0, 15),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
