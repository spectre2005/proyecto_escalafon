<?php

namespace Database\Factories;

use App\Models\PeriodoVacacional;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PeriodoVacacional> */
class PeriodoVacacionalFactory extends Factory
{
    protected $model = PeriodoVacacional::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'vinculo_laboral_id' => VinculoLaboral::factory(),
            'periodo_anio' => 1,
            'registrado_por' => Usuario::factory(),
        ];
    }
}
