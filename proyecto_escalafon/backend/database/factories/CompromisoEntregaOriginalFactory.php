<?php

namespace Database\Factories;

use App\Models\CompromisoEntregaOriginal;
use App\Models\Legajo;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompromisoEntregaOriginal> */
class CompromisoEntregaOriginalFactory extends Factory
{
    protected $model = CompromisoEntregaOriginal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'legajo_id' => Legajo::factory(),
            'motivo_impedimento' => fake()->sentence(),
            'fecha_compromiso' => now(),
            'fecha_limite' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
