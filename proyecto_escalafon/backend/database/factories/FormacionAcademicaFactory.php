<?php

namespace Database\Factories;

use App\Models\FormacionAcademica;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormacionAcademica> */
class FormacionAcademicaFactory extends Factory
{
    protected $model = FormacionAcademica::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'nivel' => substr(fake()->word(), 0, 30),
            'denominacion' => substr(fake()->word(), 0, 200),
            'institucion' => substr(fake()->word(), 0, 200),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
