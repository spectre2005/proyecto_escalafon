<?php

namespace Database\Factories;

use App\Models\PerfilDocente;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PerfilDocente> */
class PerfilDocenteFactory extends Factory
{
    protected $model = PerfilDocente::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'vinculo_laboral_id' => VinculoLaboral::factory(),
            'condicion_docente' => substr(fake()->word(), 0, 15),
        ];
    }
}
