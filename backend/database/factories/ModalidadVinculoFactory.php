<?php

namespace Database\Factories;

use App\Models\ModalidadVinculo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ModalidadVinculo> */
class ModalidadVinculoFactory extends Factory
{
    protected $model = ModalidadVinculo::class;

    public function definition(): array
    {
        return [
            'codigo' => 'MOD-'.fake()->unique()->numerify('9###'),
            'nombre' => 'Modalidad '.fake()->unique()->word(),
            'regimen_laboral' => 'plazo_determinado',
            'tipo_personal' => null,
            'exige_fin_previsto' => false,
            'activo' => true,
        ];
    }
}
