<?php

namespace Database\Seeders;

use App\Models\Induccion;
use App\Models\Legajo;
use App\Models\PerfilAdministrativo;
use App\Models\PerfilDocente;
use App\Models\PeriodoPrueba;
use App\Models\RegimenNormativo;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dos personas ficticias para recorrer la sección 02 en local. Las fechas son relativas a hoy para que
 * siempre se vean los avisos (vínculo por vencer, período de prueba pendiente y vencido).
 * - 99000201: legajo, vínculo vigente con adendas, historial, inducciones (una anulada) y período de prueba.
 * - 99000202: legajo y vínculo vigente sin período de prueba (2.4 atenuada) y con una inducción faltante.
 * No lo llama DatabaseSeeder: php artisan db:seed --class=Seccion02DemoSeeder
 */
class Seccion02DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $this->call(Seccion02CatalogoSeeder::class);
        if (Legajo::query()->where('numero_legajo', 'DEMO-S02-001')->exists()) {
            $this->command?->info('Seccion02DemoSeeder: los datos de demostración ya existen.');

            return;
        }

        DB::transaction(function (): void {
            $hoy = today();
            $regimen = RegimenNormativo::query()->where('activo', true)->first() ?? RegimenNormativo::factory()->create(['codigo' => 'OTRO', 'nombre' => 'Régimen de demostración', 'aplica_a' => 'ambos']);
            $persona = fn (string $doc, string $nombres, string $tipo, bool $prueba): Usuario => Usuario::factory()->create([
                'numero_doc_identidad' => $doc, 'nombres' => $nombres, 'apellido_paterno' => 'Ficticia', 'apellido_materno' => 'Incorporación',
                'email_institucional' => 'demo'.$doc.'@example.test', 'tipo_personal' => $tipo, 'tiene_periodo_prueba' => $prueba,
                'regimen_laboral' => $tipo === 'docente' ? '30220' : 'CAS', 'nombre_puesto' => $tipo === 'docente' ? 'Docente' : 'Especialista administrativo',
            ]);
            $conPrueba = $persona('99000201', 'Persona Demo Con Prueba', 'administrativo', true);
            $sinPrueba = $persona('99000202', 'Persona Demo Sin Prueba', 'docente', false);
            $registrador = Usuario::query()->whereHas('usuarioRolesPorUsuario.rol', fn ($query) => $query->where('codigo', 'ADMIN'))->value('id') ?? $conPrueba->id;
            $fecha = fn (int $dias) => $hoy->copy()->addDays($dias)->toDateString();

            Legajo::query()->create(['usuario_id' => $conPrueba->id, 'numero_legajo' => 'DEMO-S02-001', 'fecha_apertura' => $fecha(-200)]);
            Legajo::query()->create(['usuario_id' => $sinPrueba->id, 'numero_legajo' => 'DEMO-S02-002', 'fecha_apertura' => $fecha(-400)]);

            // Historial: un vínculo anterior ya cesado.
            VinculoLaboral::query()->create([
                'usuario_id' => $conPrueba->id, 'tipo_personal' => 'administrativo', 'regimen_laboral' => 'plazo_determinado', 'regimen_normativo_id' => $regimen->id,
                'nombre_puesto' => 'Asistente administrativo', 'numero_documento_vinculo' => 'DEMO-CONTRATO-001', 'proceso_seleccion' => 'Proceso DEMO-001',
                'fecha_resultado_seleccion' => $fecha(-760), 'fecha_inicio' => $fecha(-750), 'fecha_fin_prevista' => $fecha(-385), 'fecha_cese' => $fecha(-385),
            ]);
            $vigente = VinculoLaboral::query()->create([
                'usuario_id' => $conPrueba->id, 'tipo_personal' => 'administrativo', 'regimen_laboral' => 'plazo_determinado', 'regimen_normativo_id' => $regimen->id,
                'nombre_puesto' => 'Especialista administrativo', 'numero_documento_vinculo' => 'DEMO-CONTRATO-002', 'tiene_periodo_prueba' => true,
                'proceso_seleccion' => 'Proceso DEMO-002', 'fecha_resultado_seleccion' => $fecha(-190), 'fecha_inicio' => $fecha(-180), 'fecha_fin_prevista' => $fecha(-90),
            ]);
            PerfilAdministrativo::query()->create(['vinculo_laboral_id' => $vigente->id, 'grupo_ocupacional' => 'profesional', 'nivel_carrera' => 'SP-ES', 'cargo_estructural' => 'Especialista']);
            // Adendas: la última fija el fin efectivo (por vencer).
            VinculoAdenda::query()->create(['vinculo_laboral_id' => $vigente->id, 'numero' => 'DEMO-AD-01', 'motivo' => 'Ampliación de plazo', 'fecha_inicio' => $fecha(-89), 'fecha_fin' => $fecha(-1), 'registrado_por' => $registrador]);
            VinculoAdenda::query()->create(['vinculo_laboral_id' => $vigente->id, 'numero' => 'DEMO-AD-02', 'motivo' => 'Segunda ampliación de plazo', 'fecha_inicio' => $fecha(0), 'fecha_fin' => $fecha(20), 'registrado_por' => $registrador]);
            $base = ['usuario_id' => $conPrueba->id, 'vinculo_laboral_id' => $vigente->id, 'registrado_por' => $registrador];
            Induccion::query()->create($base + ['tipo' => 'general', 'fecha' => $fecha(-178), 'responsable' => 'Oficina de Recursos Humanos']);
            Induccion::query()->create($base + ['tipo' => 'especifica', 'fecha' => $fecha(-175), 'responsable' => 'Jefatura inmediata']);
            Induccion::query()->create($base + ['tipo' => 'especifica', 'fecha' => $fecha(-176), 'responsable' => 'Jefatura inmediata', 'anulado' => true, 'motivo_anulacion' => 'Fecha mal registrada (demostración).']);
            // Período de prueba pendiente con la fecha de fin ya pasada: debe salir la alerta.
            PeriodoPrueba::query()->create($base + ['fecha_inicio' => $fecha(-180), 'fecha_fin' => $fecha(-90)]);

            $docente = VinculoLaboral::query()->create([
                'usuario_id' => $sinPrueba->id, 'tipo_personal' => 'docente', 'regimen_laboral' => 'carrera', 'regimen_normativo_id' => $regimen->id,
                'nombre_puesto' => 'Docente', 'numero_documento_vinculo' => 'DEMO-RES-010', 'proceso_seleccion' => 'Concurso DEMO-010',
                'fecha_resultado_seleccion' => $fecha(-380), 'fecha_inicio' => $fecha(-365),
            ]);
            PerfilDocente::query()->create(['vinculo_laboral_id' => $docente->id, 'condicion_docente' => 'ordinario', 'categoria' => 'auxiliar', 'dedicacion' => 'tiempo_completo']);
            Induccion::query()->create(['usuario_id' => $sinPrueba->id, 'vinculo_laboral_id' => $docente->id, 'registrado_por' => $registrador, 'tipo' => 'general', 'fecha' => $fecha(-360)]);
        });
    }
}
