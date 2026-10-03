<?php

namespace Database\Factories;

use App\Models\Afiliacion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Afiliacion> */
class AfiliacionFactory extends Factory
{
    protected $model = Afiliacion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'tipo' => substr(fake()->word(), 0, 15),
            'entidad' => substr(fake()->word(), 0, 150),
            'fecha_inicio' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
