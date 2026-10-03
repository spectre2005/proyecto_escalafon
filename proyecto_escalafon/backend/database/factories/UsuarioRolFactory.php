<?php

namespace Database\Factories;

use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsuarioRol> */
class UsuarioRolFactory extends Factory
{
    protected $model = UsuarioRol::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'rol_id' => Rol::factory(),
            'vigente_desde' => now(),
            'asignado_por' => Usuario::factory(),
        ];
    }
}
