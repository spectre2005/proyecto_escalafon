<?php

namespace Database\Factories;

use App\Models\Legajo;
use App\Models\MovimientoCustodiaLegajo;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MovimientoCustodiaLegajo> */
class MovimientoCustodiaLegajoFactory extends Factory
{
    protected $model = MovimientoCustodiaLegajo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'legajo_id' => Legajo::factory(),
            'tipo_movimiento' => substr(fake()->word(), 0, 35),
            'autorizado_por' => Usuario::factory(),
            'fecha_movimiento' => now(),
        ];
    }
}
