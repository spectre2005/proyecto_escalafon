<?php

namespace Database\Factories;

use App\Models\Desvinculacion;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Desvinculacion> */
class DesvinculacionFactory extends Factory
{
    protected $model = Desvinculacion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'vinculo_laboral_id' => VinculoLaboral::factory(),
            'motivo_cese' => substr(fake()->word(), 0, 25),
            'fecha_cese' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
