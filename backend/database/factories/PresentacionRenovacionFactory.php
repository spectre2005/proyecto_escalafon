<?php

namespace Database\Factories;

use App\Models\PresentacionRenovacion;
use App\Models\RequerimientoDocumento;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PresentacionRenovacion>
 */
class PresentacionRenovacionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requerimiento_id' => RequerimientoDocumento::factory(),
            'storage_path' => 'renovaciones/'.fake()->uuid().'.pdf', 'nombre_original' => 'renovacion.pdf',
            'hash_sha256' => hash('sha256', fake()->uuid()), 'tamano_bytes' => 100,
            'subido_por' => Usuario::factory(), 'actual' => true,
        ];
    }
}
