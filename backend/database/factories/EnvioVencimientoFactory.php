<?php

namespace Database\Factories;

use App\Models\AvisoVencimiento;
use App\Models\EnvioVencimiento;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnvioVencimiento>
 */
class EnvioVencimientoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'aviso_id' => AvisoVencimiento::factory(),
            'destinatario_id' => fn (array $a) => AvisoVencimiento::findOrFail($a['aviso_id'])->ciclo->legajo->usuario_id,
            'destino' => fn (array $a) => Usuario::findOrFail($a['destinatario_id'])->email_institucional,
            'estado' => 'pendiente', 'canal' => 'correo',
        ];
    }
}
