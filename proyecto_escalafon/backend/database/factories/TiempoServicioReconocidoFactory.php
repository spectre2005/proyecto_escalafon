<?php

namespace Database\Factories;

use App\Models\TiempoServicioReconocido;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TiempoServicioReconocido> */
class TiempoServicioReconocidoFactory extends Factory
{
    protected $model = TiempoServicioReconocido::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'numero_resolucion' => substr(fake()->word(), 0, 60),
            'fecha_resolucion' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
