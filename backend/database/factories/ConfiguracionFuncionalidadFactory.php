<?php

namespace Database\Factories;

use App\Models\ConfiguracionFuncionalidad;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConfiguracionFuncionalidad> */
class ConfiguracionFuncionalidadFactory extends Factory
{
    protected $model = ConfiguracionFuncionalidad::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'codigo' => 'prueba.'.fake()->unique()->lexify('????????'),
            'activa' => true,
            'motivo' => 'Registro de prueba',
            'updated_by' => Usuario::factory(),
        ];
    }
}
