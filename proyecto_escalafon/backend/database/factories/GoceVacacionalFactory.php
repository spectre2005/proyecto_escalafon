<?php

namespace Database\Factories;

use App\Models\GoceVacacional;
use App\Models\PeriodoVacacional;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoceVacacional> */
class GoceVacacionalFactory extends Factory
{
    protected $model = GoceVacacional::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'periodo_vacacional_id' => PeriodoVacacional::factory(),
            'tipo' => substr(fake()->word(), 0, 15),
            'fecha_inicio' => now(),
            'fecha_fin' => now(),
            'dias' => 1,
            'registrado_por' => Usuario::factory(),
        ];
    }
}
