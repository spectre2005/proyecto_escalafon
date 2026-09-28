<?php

namespace Database\Factories;

use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VinculoAdenda> */
class VinculoAdendaFactory extends Factory
{
    protected $model = VinculoAdenda::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'vinculo_laboral_id' => VinculoLaboral::factory(),
            'fecha_inicio' => now(),
            'fecha_fin' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
