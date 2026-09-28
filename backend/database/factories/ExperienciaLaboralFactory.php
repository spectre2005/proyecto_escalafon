<?php

namespace Database\Factories;

use App\Models\ExperienciaLaboral;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExperienciaLaboral> */
class ExperienciaLaboralFactory extends Factory
{
    protected $model = ExperienciaLaboral::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'entidad_empresa' => substr(fake()->word(), 0, 200),
            'sector' => substr(fake()->word(), 0, 10),
            'cargo' => substr(fake()->word(), 0, 150),
            'fecha_inicio' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
