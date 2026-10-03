<?php

namespace Database\Factories;

use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TipoDocumento> */
class TipoDocumentoFactory extends Factory
{
    protected $model = TipoDocumento::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'seccion_legajo_id' => SeccionLegajo::factory(),
            'codigo' => substr(fake()->word(), 0, 40),
            'nombre' => substr(fake()->word(), 0, 200),
        ];
    }
}
