<?php

namespace Database\Factories;

use App\Models\NotificacionUsuario;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificacionUsuario> */
class NotificacionUsuarioFactory extends Factory
{
    protected $model = NotificacionUsuario::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'tipo' => substr(fake()->word(), 0, 30),
            'titulo' => substr(fake()->word(), 0, 150),
            'mensaje' => fake()->sentence(),
        ];
    }
}
