<?php

namespace Database\Factories;

use App\Models\RegistroSstBienestar;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegistroSstBienestar> */
class RegistroSstBienestarFactory extends Factory
{
    protected $model = RegistroSstBienestar::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'tipo' => substr(fake()->word(), 0, 30),
            'fecha' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
