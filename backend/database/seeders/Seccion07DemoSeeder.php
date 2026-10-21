<?php

namespace Database\Seeders;

use App\Models\Desplazamiento;
use App\Models\EvaluacionDesempeno;
use App\Models\Legajo;
use App\Models\PerfilAdministrativo;
use App\Models\PerfilDocente;
use App\Models\ProgresionCarrera;
use App\Models\RatificacionDocente;
use App\Models\RegimenNormativo;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos ficticios de la sección 07 (solo local y testing). Dos partes, cada una idempotente:
 * - Consulta: registros sobre las personas demo de UsuarioSeeder para la pantalla Evaluaciones.
 * - Legajo: tres personas con legajo abierto para recorrer la ficha (fechas relativas a hoy):
 *   99000701 administrativa de carrera (evaluaciones, una progresión y desplazamientos de varios tipos),
 *   99000702 docente de carrera (una ratificación por vencer) y 99000703 de plazo determinado (7.2 no aplica).
 * No lo llama DatabaseSeeder: php artisan db:seed --class=Seccion07DemoSeeder
 */
class Seccion07DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $this->call(Seccion07CatalogoSeeder::class);
        $this->legajos();
        if (ProgresionCarrera::query()->where('numero_resolucion', 'like', 'DEMO-RP-%')->exists()) {
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

    private function legajos(): void
    {
        if (Legajo::query()->where('numero_legajo', 'DEMO-S07-001')->exists()) {
            return;
        }
        DB::transaction(function (): void {
            $hoy = today();
            $fecha = fn (int $dias): string => $hoy->copy()->addDays($dias)->toDateString();
            $regimen = RegimenNormativo::query()->where('activo', true)->first() ?? RegimenNormativo::factory()->create(['codigo' => 'OTRO', 'nombre' => 'Régimen de demostración', 'aplica_a' => 'ambos']);
            $unidades = collect([['DEMO-OGP', 'Oficina de Gestión de Personas', 'oficina'], ['DEMO-FI', 'Facultad de Ingeniería', 'facultad']])
                ->map(fn (array $u): UnidadOrganica => UnidadOrganica::query()->firstOrCreate(['codigo' => $u[0]], ['nombre' => $u[1], 'tipo' => $u[2], 'activo' => true]));
            $persona = fn (string $doc, string $nombres, string $tipo, string $regimen): Usuario => Usuario::factory()->create([
                'numero_doc_identidad' => $doc, 'nombres' => $nombres, 'apellido_paterno' => 'Ficticia', 'apellido_materno' => 'Evaluación',
                'email_institucional' => 's07.'.$doc.'@example.test', 'tipo_personal' => $tipo, 'regimen_laboral' => $regimen,
                'nombre_puesto' => $tipo === 'docente' ? 'Docente' : 'Especialista administrativo', 'unidad_organica_id' => $unidades[0]->id,
            ]);
            $carrera = $persona('99000701', 'Persona Demo Carrera', 'administrativo', '276');
            $docente = $persona('99000702', 'Persona Demo Docente', 'docente', '30220');
            $plazo = $persona('99000703', 'Persona Demo Plazo', 'administrativo', 'CAS');
            $registrador = Usuario::query()->whereHas('usuarioRolesPorUsuario.rol', fn ($query) => $query->where('codigo', 'ADMIN'))->value('id') ?? $carrera->id;
            foreach ([[$carrera, '001'], [$docente, '002'], [$plazo, '003']] as [$p, $n]) {
                Legajo::query()->create(['usuario_id' => $p->id, 'numero_legajo' => 'DEMO-S07-'.$n, 'fecha_apertura' => $fecha(-900)]);
            }
            $vinculo = fn (Usuario $p, string $tipo, string $regimenLaboral, string $puesto): VinculoLaboral => VinculoLaboral::query()->create([
                'usuario_id' => $p->id, 'tipo_personal' => $tipo, 'regimen_laboral' => $regimenLaboral, 'regimen_normativo_id' => $regimen->id,
                'unidad_organica_id' => $unidades[0]->id, 'nombre_puesto' => $puesto, 'numero_documento_vinculo' => 'DEMO-S07-VIN-'.$p->numero_doc_identidad, 'fecha_inicio' => $fecha(-1500),
            ]);
            $base = fn (Usuario $p): array => ['usuario_id' => $p->id, 'registrado_por' => $registrador];

            // Administrativa de carrera: evaluaciones (una con plan de mejora), una progresión y tres desplazamientos.
            $vCarrera = $vinculo($carrera, 'administrativo', 'carrera', 'Especialista administrativo');
            PerfilAdministrativo::query()->create(['vinculo_laboral_id' => $vCarrera->id, 'grupo_ocupacional' => 'profesional', 'nivel_carrera' => 'SP-ES']);
            $anio = (int) $hoy->year;
            EvaluacionDesempeno::query()->create($base($carrera) + ['ciclo_anio' => $anio - 2, 'fecha_evaluacion' => ($anio - 2).'-12-15', 'calificacion' => 'Buen rendimiento', 'puntaje' => 81.5, 'evaluador_id' => $registrador]);
            EvaluacionDesempeno::query()->create($base($carrera) + ['ciclo_anio' => $anio - 1, 'fecha_evaluacion' => ($anio - 1).'-12-15', 'calificacion' => 'Sujeto a observación', 'puntaje' => 58.0, 'plan_mejora' => true, 'evaluador_id' => $registrador]);
            ProgresionCarrera::query()->create($base($carrera) + ['vinculo_laboral_id' => $vCarrera->id, 'nivel_anterior' => 'SP-AP', 'nivel_nuevo' => 'SP-ES', 'fecha_efectiva' => $fecha(-400), 'numero_resolucion' => 'DEMO-S07-RP-001']);
            foreach ([['rotacion', -700, -400], ['destaque', -300, -120], ['encargo_funciones', -60, null]] as $i => [$tipo, $inicio, $fin]) {
                Desplazamiento::query()->create($base($carrera) + ['tipo' => $tipo, 'unidad_origen_id' => $unidades[0]->id, 'unidad_destino_id' => $unidades[1]->id,
                    'puesto_destino' => 'Coordinador(a) administrativo(a)', 'fecha_inicio' => $fecha($inicio), 'fecha_fin' => $fin === null ? null : $fecha($fin), 'numero_documento' => 'DEMO-S07-DS-00'.($i + 1)]);
            }

            // Docente de carrera: perfil ordinario y una ratificación cuyo período vence en 45 días (sale el aviso).
            $vDocente = $vinculo($docente, 'docente', 'carrera', 'Docente');
            $perfil = PerfilDocente::query()->create(['vinculo_laboral_id' => $vDocente->id, 'condicion_docente' => 'ordinario', 'categoria' => 'asociado', 'dedicacion' => 'tiempo_completo']);
            RatificacionDocente::query()->create($base($docente) + ['perfil_docente_id' => $perfil->id, 'tipo' => 'ratificacion', 'categoria_anterior' => 'asociado', 'categoria_nueva' => 'asociado',
                'numero_resolucion' => 'DEMO-S07-RR-001', 'fecha_resolucion' => $fecha(-1780), 'fecha_inicio_periodo' => $fecha(-1780), 'fecha_vencimiento_periodo' => $fecha(45)]);

            // Plazo determinado (no de carrera): 7.2 no aplica; una evaluación del año anterior.
            $vinculo($plazo, 'administrativo', 'plazo_determinado', 'Asistente administrativo');
            EvaluacionDesempeno::query()->create($base($plazo) + ['ciclo_anio' => $anio - 1, 'fecha_evaluacion' => ($anio - 1).'-12-10', 'calificacion' => 'Buen rendimiento', 'puntaje' => 75]);
        });
    }
}
