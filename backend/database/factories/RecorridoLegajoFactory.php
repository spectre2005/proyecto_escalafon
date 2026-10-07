<?php

namespace Database\Factories;

use App\Models\Legajo;
use App\Models\RecorridoLegajo;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecorridoLegajo> */
class RecorridoLegajoFactory extends Factory
{
    protected $model = RecorridoLegajo::class;

    public function definition(): array
    {
        return ['legajo_id' => Legajo::factory(), 'actor_usuario_id' => Usuario::factory(), 'seccion_actual_id' => null, 'secciones_omitidas' => []];
    }
}
