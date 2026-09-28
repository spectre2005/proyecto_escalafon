<?php

namespace Database\Factories;

use App\Models\Legajo;
use App\Models\SolicitudAccesoLegajo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SolicitudAccesoLegajo> */
class SolicitudAccesoLegajoFactory extends Factory
{
    protected $model = SolicitudAccesoLegajo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'legajo_id' => Legajo::factory(),
            'solicitante_nombre' => substr(fake()->word(), 0, 200),
            'solicitante_documento' => substr(fake()->word(), 0, 20),
            'tipo_solicitante' => substr(fake()->word(), 0, 20),
            'detalle_requerido' => fake()->sentence(),
            'sustento' => fake()->sentence(),
            'forma_entrega' => substr(fake()->word(), 0, 10),
        ];
    }
}
