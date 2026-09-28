<?php

namespace Database\Factories;

use App\Models\Legajo;
use App\Models\ReferenciaLegajoPasivo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReferenciaLegajoPasivo> */
class ReferenciaLegajoPasivoFactory extends Factory
{
    protected $model = ReferenciaLegajoPasivo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'legajo_id' => Legajo::factory(),
            'vinculo_laboral_id' => VinculoLaboral::factory(),
            'tipo_documento_id' => TipoDocumento::factory(),
            'folio_inicio_pasivo' => 1,
            'folio_fin_pasivo' => 1,
            'responsable_legajo_id' => Usuario::factory(),
        ];
    }
}
