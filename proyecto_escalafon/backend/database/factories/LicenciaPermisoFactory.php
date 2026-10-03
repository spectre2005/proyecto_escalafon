<?php

namespace Database\Factories;

use App\Models\LicenciaPermiso;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LicenciaPermiso> */
class LicenciaPermisoFactory extends Factory
{
    protected $model = LicenciaPermiso::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'tipo' => substr(fake()->word(), 0, 30),
            'fecha_inicio' => now(),
            'fecha_fin' => now(),
            'dias' => 1,
            'registrado_por' => Usuario::factory(),
        ];
    }
}
