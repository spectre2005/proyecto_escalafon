<?php

namespace Database\Factories;

use App\Models\DocumentoUsuario;
use App\Models\ProcesoFiscalizacion;
use App\Models\SolicitudFiscalizacion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SolicitudFiscalizacion> */
class SolicitudFiscalizacionFactory extends Factory
{
    protected $model = SolicitudFiscalizacion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'proceso_fiscalizacion_id' => ProcesoFiscalizacion::factory(),
            'documento_id' => DocumentoUsuario::factory(),
            'entidad_consultada' => substr(fake()->word(), 0, 200),
            'medio' => substr(fake()->word(), 0, 30),
            'fecha_envio' => now(),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
