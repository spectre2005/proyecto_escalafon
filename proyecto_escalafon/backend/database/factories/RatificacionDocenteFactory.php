<?php

namespace Database\Factories;

use App\Models\PerfilDocente;
use App\Models\RatificacionDocente;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RatificacionDocente> */
class RatificacionDocenteFactory extends Factory
{
    protected $model = RatificacionDocente::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'perfil_docente_id' => PerfilDocente::factory(),
            'tipo' => substr(fake()->word(), 0, 15),
            'categoria_nueva' => substr(fake()->word(), 0, 10),
            'numero_resolucion' => substr(fake()->word(), 0, 60),
            'fecha_resolucion' => now(),
            'fecha_inicio_periodo' => now(),
            'fecha_vencimiento_periodo' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
