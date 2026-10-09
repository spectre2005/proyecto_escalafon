<?php

namespace Database\Factories;

use App\Models\AvisoVencimiento;
use App\Models\CicloVigencia;
use App\Support\VigenciaLegajo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvisoVencimiento>
 */
class AvisoVencimientoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ciclo_id' => CicloVigencia::factory(), 'clave' => 'manual:'.fake()->uuid(),
            'origen' => 'manual', 'fecha_programada' => VigenciaLegajo::hoy()->toDateString(),
        ];
    }
}
