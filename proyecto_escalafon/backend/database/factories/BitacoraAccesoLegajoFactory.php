<?php

namespace Database\Factories;

use App\Models\BitacoraAccesoLegajo;
use App\Models\Legajo;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BitacoraAccesoLegajo> */
class BitacoraAccesoLegajoFactory extends Factory
{
    protected $model = BitacoraAccesoLegajo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'legajo_id' => Legajo::factory(),
            'usuario_id' => Usuario::factory(),
            'accion' => substr(fake()->word(), 0, 15),
        ];
    }
}
