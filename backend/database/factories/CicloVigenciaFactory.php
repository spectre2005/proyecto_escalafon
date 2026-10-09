<?php

namespace Database\Factories;

use App\Models\CicloVigencia;
use App\Models\Legajo;
use App\Models\PerfilDocente;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\VigenciaLegajo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<CicloVigencia>
 */
class CicloVigenciaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legajo_id' => Legajo::factory()->state(['usuario_id' => Usuario::factory()->state(['tipo_personal' => 'docente']), 'estado_legajo' => 'activo']),
            'perfil_docente_id' => fn (array $a) => PerfilDocente::factory()->create([
                'vinculo_laboral_id' => VinculoLaboral::factory()->create(['usuario_id' => Legajo::findOrFail($a['legajo_id'])->usuario_id, 'tipo_personal' => 'docente', 'fecha_inicio' => '2020-01-01'])->id,
                'condicion_docente' => 'ordinario', 'categoria' => 'auxiliar',
            ])->id,
            'regla_id' => fn () => DB::table('reglas_vigencia_categoria')->where('categoria', 'auxiliar')->value('id'),
            'categoria' => 'auxiliar', 'anios' => 3, 'version_regla' => 1,
            'fecha_base' => VigenciaLegajo::hoy()->toDateString(),
            'fecha_vencimiento' => VigenciaLegajo::hoy()->addYearsNoOverflow(3)->toDateString(),
            'fecha_aviso' => VigenciaLegajo::hoy()->addYearsNoOverflow(3)->subDays(7)->toDateString(),
            'origen_base' => 'renovacion_aprobada', 'actual' => true, 'estado_vigencia' => 'vigente',
        ];
    }
}
