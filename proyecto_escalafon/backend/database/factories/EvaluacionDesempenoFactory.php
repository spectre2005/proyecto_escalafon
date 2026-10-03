<?php

namespace Database\Factories;

use App\Models\EvaluacionDesempeno;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EvaluacionDesempeno> */
class EvaluacionDesempenoFactory extends Factory
{
    protected $model = EvaluacionDesempeno::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'ciclo_anio' => 1,
            'calificacion' => substr(fake()->word(), 0, 40),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
