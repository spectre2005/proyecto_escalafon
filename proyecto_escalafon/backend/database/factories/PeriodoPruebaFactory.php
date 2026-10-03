<?php

namespace Database\Factories;

use App\Models\PeriodoPrueba;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PeriodoPrueba> */
class PeriodoPruebaFactory extends Factory
{
    protected $model = PeriodoPrueba::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'vinculo_laboral_id' => VinculoLaboral::factory(),
            'fecha_inicio' => now(),
            'fecha_fin' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
