<?php

namespace Database\Seeders;

use App\Models\Desplazamiento;
use App\Models\EvaluacionDesempeno;
use App\Models\PerfilDocente;
use App\Models\ProgresionCarrera;
use App\Models\RatificacionDocente;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos ficticios de la sección 07 para probar la pantalla Evaluaciones en local.
 * No lo llama DatabaseSeeder: php artisan db:seed --class=Seccion07DemoSeeder
 */
class Seccion07DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        if (ProgresionCarrera::query()->where('numero_resolucion', 'like', 'DEMO-%')->exists()) {
            $this->command?->info('Seccion07DemoSeeder: los datos de demostración ya existen.');

            return;
        }
        $this->call(UsuarioSeeder::class);

        DB::transaction(function (): void {
            $unidades = collect([
                ['DEMO-OGP', 'Oficina de Gestión de Personas', 'oficina'],
                ['DEMO-FI', 'Facultad de Ingeniería', 'facultad'],
                ['DEMO-FE', 'Facultad de Educación', 'facultad'],
            ])->map(fn (array $u): UnidadOrganica => UnidadOrganica::query()->firstOrCreate(['codigo' => $u[0]], ['nombre' => $u[1], 'tipo' => $u[2], 'activo' => true]));

            $personas = Usuario::query()->where('email_institucional', 'like', 'demo%@example.test')->orderBy('numero_doc_identidad')->get();
            foreach ($personas as $i => $persona) {
                if (! $persona->unidad_organica_id) {
                    $persona->update(['unidad_organica_id' => $unidades[$i % 3]->id]);
                }
            }
            $registrador = $personas->first()->id;
            $evaluador = $personas->get(1)->id;
            $base = fn (Usuario $p): array => ['usuario_id' => $p->id, 'registrado_por' => $registrador];

            $calificaciones = [['Rendimiento distinguido', 92.5, false], ['Buen rendimiento', 78.0, false], ['Sujeto a observación', 58.25, true]];
            foreach ($personas as $i => $persona) {
                foreach ([2024, 2025] as $j => $ciclo) {
                    [$calificacion, $puntaje, $plan] = $calificaciones[($i + $j) % 3];
                    EvaluacionDesempeno::query()->create($base($persona) + [
                        'ciclo_anio' => $ciclo, 'fecha_evaluacion' => $ciclo.'-12-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT),
                        'calificacion' => $calificacion, 'puntaje' => $puntaje, 'plan_mejora' => $plan, 'evaluador_id' => $evaluador,
                    ]);
                }
            }
            // Anulada: no debe aparecer en la consulta.
            EvaluacionDesempeno::query()->create($base($personas->first()) + [
                'ciclo_anio' => 2023, 'calificacion' => 'Buen rendimiento', 'puntaje' => 80, 'anulado' => true, 'motivo_anulacion' => 'Registro duplicado (demostración).',
            ]);

            foreach ($personas->where('tipo_personal', 'administrativo')->take(2)->values() as $i => $persona) {
                $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'tipo_personal' => 'administrativo', 'nombre_puesto' => 'Especialista administrativo']);
                ProgresionCarrera::query()->create($base($persona) + [
                    'vinculo_laboral_id' => $vinculo->id, 'nivel_anterior' => 'SP-AP', 'nivel_nuevo' => 'SP-ES',
                    'fecha_efectiva' => (2024 + $i).'-07-01', 'numero_resolucion' => 'DEMO-RP-'.(101 + $i),
                ]);
            }

            foreach ($personas->where('tipo_personal', 'docente')->take(2)->values() as $i => $persona) {
                $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'tipo_personal' => 'docente', 'nombre_puesto' => 'Docente']);
                $perfil = PerfilDocente::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'condicion_docente' => 'ordinario']);
                RatificacionDocente::query()->create($base($persona) + [
                    'perfil_docente_id' => $perfil->id, 'tipo' => $i ? 'promocion' : 'ratificacion',
                    'categoria_anterior' => $i ? 'auxiliar' : 'asociado', 'categoria_nueva' => 'asociado',
                    'numero_resolucion' => 'DEMO-RR-'.(201 + $i), 'fecha_resolucion' => '2023-0'.(3 + $i).'-15',
                    'fecha_inicio_periodo' => '2023-0'.(3 + $i).'-15', 'fecha_vencimiento_periodo' => '2028-0'.(3 + $i).'-14',
                    'resultado' => $i ? 'promovido' : 'ratificado',
                ]);
            }

            $tipos = [['rotacion', '2025-02-01', null], ['destaque', '2025-06-15', '2025-12-31'], ['encargo_funciones', '2026-01-05', null]];
            foreach ($personas->take(3)->values() as $i => $persona) {
                [$tipo, $inicio, $fin] = $tipos[$i];
                Desplazamiento::query()->create($base($persona) + [
                    'tipo' => $tipo, 'unidad_origen_id' => $unidades[$i % 3]->id, 'unidad_destino_id' => $unidades[($i + 1) % 3]->id,
                    'puesto_destino' => 'Asistente administrativo', 'fecha_inicio' => $inicio, 'fecha_fin' => $fin, 'numero_documento' => 'DEMO-MEM-'.(301 + $i),
                ]);
            }
        });
    }
}
