<?php

namespace Database\Factories;

use App\Models\Capacitacion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Capacitacion> */
class CapacitacionFactory extends Factory
{
    protected $model = Capacitacion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'tipo' => substr(fake()->word(), 0, 25),
            'nombre' => substr(fake()->word(), 0, 250),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
