<?php

namespace Database\Factories;

use App\Models\RegimenNormativo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VinculoLaboral> */
class VinculoLaboralFactory extends Factory
{
    protected $model = VinculoLaboral::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'tipo_personal' => 'administrativo',
            'regimen_laboral' => 'plazo_determinado',
            'regimen_normativo_id' => RegimenNormativo::factory(),
            'nombre_puesto' => substr(fake()->word(), 0, 150),
            // Día de Lima, como VigenciaLaboral: con now() en UTC, de 19:00 a 24:00 en Lima el vínculo nacería «con inicio futuro».
            'fecha_inicio' => now('America/Lima')->toDateString(),
        ];
    }
}
