<?php

namespace Database\Factories;

use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\SeccionLegajo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LegajoSeccion> */
class LegajoSeccionFactory extends Factory
{
    protected $model = LegajoSeccion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'legajo_id' => Legajo::factory(),
            'seccion_legajo_id' => SeccionLegajo::factory(),
        ];
    }
}
