<?php

namespace Database\Factories;

use App\Models\CertificadoDiscapacidad;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CertificadoDiscapacidad> */
class CertificadoDiscapacidadFactory extends Factory
{
    protected $model = CertificadoDiscapacidad::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'persona' => substr(fake()->word(), 0, 10),
            'numero_conadis' => substr(fake()->word(), 0, 30),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
