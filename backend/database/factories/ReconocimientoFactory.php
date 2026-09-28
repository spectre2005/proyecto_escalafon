<?php

namespace Database\Factories;

use App\Models\Reconocimiento;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reconocimiento> */
class ReconocimientoFactory extends Factory
{
    protected $model = Reconocimiento::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'tipo' => substr(fake()->word(), 0, 20),
            'fecha' => now(),
            'motivo' => substr(fake()->word(), 0, 300),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
