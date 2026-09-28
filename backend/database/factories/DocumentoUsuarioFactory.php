<?php

namespace Database\Factories;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentoUsuario> */
class DocumentoUsuarioFactory extends Factory
{
    protected $model = DocumentoUsuario::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'legajo_id' => Legajo::factory(),
            'tipo_documento_id' => TipoDocumento::factory(),
            'seccion_legajo_id' => SeccionLegajo::factory(),
            'created_by' => Usuario::factory(),
        ];
    }
}
