<?php

namespace Database\Factories;

use App\Models\Sancion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Sancion> */
class SancionFactory extends Factory
{
    protected $model = Sancion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'tipo' => substr(fake()->word(), 0, 25),
            'numero_resolucion' => substr(fake()->word(), 0, 60),
            'fecha_resolucion' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
