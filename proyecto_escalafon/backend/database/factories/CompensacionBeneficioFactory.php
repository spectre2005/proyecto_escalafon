<?php

namespace Database\Factories;

use App\Models\CompensacionBeneficio;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompensacionBeneficio> */
class CompensacionBeneficioFactory extends Factory
{
    protected $model = CompensacionBeneficio::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'tipo' => substr(fake()->word(), 0, 15),
            'concepto' => substr(fake()->word(), 0, 200),
            'fecha' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
