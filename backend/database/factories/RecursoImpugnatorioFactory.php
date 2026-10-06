<?php

namespace Database\Factories;

use App\Models\DocumentoUsuario;
use App\Models\RecursoImpugnatorio;
use App\Models\Sancion;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecursoImpugnatorio> */
class RecursoImpugnatorioFactory extends Factory
{
    protected $model = RecursoImpugnatorio::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sancion_id' => Sancion::factory(),
            'documento_id' => DocumentoUsuario::factory(),
            'fecha' => now(),
            // Valores que admite Seccion08Controller.
            'resultado' => fake()->randomElement(['FUNDADO', 'INFUNDADO', 'IMPROCEDENTE']),
            'registrado_por' => Usuario::factory(),
        ];
    }
}
