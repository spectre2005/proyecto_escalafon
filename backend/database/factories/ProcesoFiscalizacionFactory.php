<?php

namespace Database\Factories;

use App\Models\Legajo;
use App\Models\ProcesoFiscalizacion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProcesoFiscalizacion> */
class ProcesoFiscalizacionFactory extends Factory
{
    protected $model = ProcesoFiscalizacion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'legajo_id' => Legajo::factory(),
            'tipo_muestra' => substr(fake()->word(), 0, 25),
            'fecha_vinculacion' => now(),
            'fecha_inicio' => now(),
            'fecha_limite' => now(),
            'responsable_id' => Usuario::factory(),
        ];
    }
}
