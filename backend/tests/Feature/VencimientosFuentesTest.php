<?php

namespace Tests\Feature;

use App\Models\CertificadoDiscapacidad;
use App\Models\Colegiatura;
use App\Models\Desplazamiento;
use App\Models\Legajo;
use App\Models\PeriodoPrueba;
use App\Models\RatificacionDocente;
use App\Models\RegistroSstBienestar;
use App\Models\RequerimientoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Carbon\Carbon;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fuentes de alerta de Vencimientos además de documentos, contratos y requerimientos: colegiatura, certificado de
 * discapacidad, examen médico ocupacional, período de prueba, ratificación docente y encargos o destaques. Solo fechas
 * registradas; lo anulado, cerrado o reemplazado no alerta; cada categoría con su permiso. Datos ficticios.
 */
class VencimientosFuentesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/vencimientos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-17 10:00:00', 'America/Lima'));
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $this->actuarComo();
    }

    private function persona(): Usuario
    {
        $persona = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'activo']);

        return $persona;
    }

    private function dia(int $dias): string
    {
        return now('America/Lima')->addDays($dias)->toDateString();
    }

    /** @return list<array{origen: string, fecha: string, estado: string}> */
    private function motivos(string $origen): array
    {
        return collect($this->getJson(self::URL.'?origen='.$origen)->assertOk()->json('data'))
            ->flatMap(fn (array $persona): array => $persona['motivos'])->map(fn (array $m): array => [$m['fecha'], $m['estado']])->all();
    }

    public function test_professional_registration_alerts_only_the_latest_dated_one(): void
    {
        $persona = $this->persona();
        Colegiatura::factory()->create(['usuario_id' => $persona->id, 'vigente_hasta' => $this->dia(-3), 'anulado' => false]);
        $otra = $this->persona();
        // Renovada: la anterior vencida no alerta; la nueva vence dentro del plazo.
        Colegiatura::factory()->create(['usuario_id' => $otra->id, 'vigente_hasta' => $this->dia(-30), 'anulado' => false]);
        Colegiatura::factory()->create(['usuario_id' => $otra->id, 'vigente_hasta' => $this->dia(10), 'anulado' => false]);
        // Sin fecha, anulada o lejana: nada.
        Colegiatura::factory()->create(['usuario_id' => $this->persona()->id, 'vigente_hasta' => null, 'anulado' => false]);
        Colegiatura::factory()->create(['usuario_id' => $this->persona()->id, 'vigente_hasta' => $this->dia(-1), 'anulado' => true, 'motivo_anulacion' => 'Error']);
        Colegiatura::factory()->create(['usuario_id' => $this->persona()->id, 'vigente_hasta' => $this->dia(60), 'anulado' => false]);

        $this->assertSame([[$this->dia(-3), 'vencido'], [$this->dia(10), 'por_vencer']], $this->motivos('colegiatura'));
    }

    public function test_disability_certificate_alerts_only_dated_and_not_replaced_ones(): void
    {
        $vencido = $this->persona();
        CertificadoDiscapacidad::factory()->create(['usuario_id' => $vencido->id, 'familiar_id' => null, 'vigente_hasta' => $this->dia(-5), 'anulado' => false]);
        // Permanente (sin fecha): no alerta.
        CertificadoDiscapacidad::factory()->create(['usuario_id' => $this->persona()->id, 'vigente_hasta' => null, 'anulado' => false]);
        // Reemplazado por uno posterior permanente: no alerta.
        $renovado = $this->persona();
        CertificadoDiscapacidad::factory()->create(['usuario_id' => $renovado->id, 'vigente_hasta' => $this->dia(-20), 'anulado' => false, 'created_at' => now()->subYear()]);
        CertificadoDiscapacidad::factory()->create(['usuario_id' => $renovado->id, 'vigente_hasta' => null, 'anulado' => false, 'created_at' => now()]);
        CertificadoDiscapacidad::factory()->create(['usuario_id' => $this->persona()->id, 'vigente_hasta' => $this->dia(-1), 'anulado' => true, 'motivo_anulacion' => 'Error']);

        $this->assertSame([[$this->dia(-5), 'vencido']], $this->motivos('discapacidad'));
    }

    public function test_occupational_exam_alerts_by_its_expiry_and_ignores_other_records(): void
    {
        $persona = $this->persona();
        RegistroSstBienestar::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'S10_EXAMEN_OCUPACIONAL', 'fecha_vencimiento' => $this->dia(-2), 'anulado' => false]);
        RegistroSstBienestar::factory()->create(['usuario_id' => $this->persona()->id, 'tipo' => 'S10_ACCIDENTES_INCIDENTES', 'fecha_vencimiento' => $this->dia(-2), 'anulado' => false]);
        $renovado = $this->persona();
        RegistroSstBienestar::factory()->create(['usuario_id' => $renovado->id, 'tipo' => 'S10_EXAMEN_OCUPACIONAL', 'fecha_vencimiento' => $this->dia(-100), 'anulado' => false]);
        RegistroSstBienestar::factory()->create(['usuario_id' => $renovado->id, 'tipo' => 'S10_EXAMEN_OCUPACIONAL', 'fecha_vencimiento' => $this->dia(200), 'anulado' => false]);

        $this->assertSame([[$this->dia(-2), 'vencido']], $this->motivos('examen_medico'));
    }

    public function test_probation_period_alerts_only_while_its_result_is_pending_on_an_open_link(): void
    {
        $persona = $this->persona();
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2026-08-01', 'fecha_cese' => null, 'anulado' => false]);
        PeriodoPrueba::factory()->create(['usuario_id' => $persona->id, 'vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2026-08-01', 'fecha_fin' => $this->dia(4), 'resultado' => 'pendiente', 'anulado' => false]);
        PeriodoPrueba::factory()->create(['usuario_id' => $persona->id, 'vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2026-08-01', 'fecha_fin' => $this->dia(-4), 'resultado' => 'aprobado', 'anulado' => false]);
        $cesado = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2026-01-01', 'fecha_cese' => '2026-09-30', 'anulado' => false]);
        PeriodoPrueba::factory()->create(['usuario_id' => $persona->id, 'vinculo_laboral_id' => $cesado->id, 'fecha_inicio' => '2026-01-01', 'fecha_fin' => $this->dia(-60), 'resultado' => 'pendiente', 'anulado' => false]);

        $this->assertSame([[$this->dia(4), 'por_vencer']], $this->motivos('periodo_prueba'));
    }

    public function test_teacher_ratification_alerts_only_while_pending(): void
    {
        $persona = $this->persona();
        RatificacionDocente::factory()->create(['usuario_id' => $persona->id, 'fecha_vencimiento_periodo' => $this->dia(-1), 'resultado' => 'pendiente', 'anulado' => false]);
        RatificacionDocente::factory()->create(['usuario_id' => $this->persona()->id, 'fecha_vencimiento_periodo' => $this->dia(-1), 'resultado' => 'ratificado', 'anulado' => false]);
        RatificacionDocente::factory()->create(['usuario_id' => $this->persona()->id, 'fecha_vencimiento_periodo' => $this->dia(3), 'resultado' => 'pendiente', 'anulado' => true, 'motivo_anulacion' => 'Error']);

        $this->assertSame([[$this->dia(-1), 'vencido']], $this->motivos('ratificacion'));
    }

    public function test_temporary_assignments_warn_before_their_end_and_never_after(): void
    {
        $persona = $this->persona();
        Desplazamiento::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'encargo_funciones', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => $this->dia(5), 'anulado' => false]);
        Desplazamiento::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'destaque', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => $this->dia(0), 'anulado' => false]);
        // Terminado por su fecha (sin estado que diga que sigue), anulado, sin fecha o no temporal: nada.
        Desplazamiento::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'encargo_puesto', 'fecha_inicio' => '2026-06-01', 'fecha_fin' => $this->dia(-1), 'anulado' => false]);
        Desplazamiento::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'destaque', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => $this->dia(3), 'anulado' => true, 'motivo_anulacion' => 'Error']);
        Desplazamiento::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'encargo_puesto', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => null, 'anulado' => false]);
        Desplazamiento::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'rotacion', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => $this->dia(2), 'anulado' => false]);

        $this->assertSame([[$this->dia(0), 'por_vencer'], [$this->dia(5), 'por_vencer']], $this->motivos('desplazamiento'));
    }

    public function test_each_category_requires_its_own_permission_including_counters(): void
    {
        $persona = $this->persona();
        CertificadoDiscapacidad::factory()->create(['usuario_id' => $persona->id, 'vigente_hasta' => $this->dia(-5), 'anulado' => false]);
        RegistroSstBienestar::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'S10_EXAMEN_OCUPACIONAL', 'fecha_vencimiento' => $this->dia(-2), 'anulado' => false]);
        RatificacionDocente::factory()->create(['usuario_id' => $persona->id, 'fecha_vencimiento_periodo' => $this->dia(-1), 'resultado' => 'pendiente', 'anulado' => false]);
        RequerimientoDocumento::factory()->create(['usuario_id' => $persona->id, 'estado' => 'abierto', 'fecha_limite' => $this->dia(2)]);

        $origenes = fn (): array => $this->getJson(self::URL)->assertOk()->json('origenes.*.id');

        // Con requerimientos.gestionar pero sin datos sensibles ni sección 07: solo el requerimiento, y los contadores
        // no cuentan lo sensible (la persona sale «por vencer», no «vencido»).
        config(['permisos.roles.prueba_requerimientos' => ['legajo.ver', 'requerimientos.gestionar']]);
        $this->actuarComo(null, 'prueba_requerimientos');
        $respuesta = $this->getJson(self::URL)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.total_alertas', 1)
            ->assertJsonPath('data.0.estado', 'por_vencer')->assertJsonPath('resumen.vencidos', 0)->assertJsonPath('resumen.por_vencer', 1);
        $this->assertSame(['requerimiento'], array_column($respuesta->json('data.0.motivos'), 'origen'));
        $this->assertSame([], array_values(array_intersect(['discapacidad', 'examen_medico', 'ratificacion', 'desplazamiento'], $origenes())));
        foreach (['discapacidad', 'examen_medico', 'ratificacion', 'desplazamiento'] as $origen) {
            $this->getJson(self::URL.'?origen='.$origen)->assertOk()->assertJsonPath('total', 0)->assertJsonPath('resumen.personas', 0);
        }
        $this->assertStringNotContainsString('Del servidor', $respuesta->getContent());

        // El asistente ve la sección 07 (ratificación) y requerimientos, pero no lo sensible.
        $this->actuarComo(null, 'asistente_legajo');
        $this->assertEqualsCanonicalizing(['requerimiento', 'ratificacion'], array_column($this->getJson(self::URL)->assertOk()->json('data.0.motivos'), 'origen'));

        // El administrador ve todo.
        $this->actuarComo();
        $this->assertEqualsCanonicalizing(['discapacidad', 'examen_medico', 'ratificacion', 'requerimiento'], array_column($this->getJson(self::URL)->assertOk()->json('data.0.motivos'), 'origen'));
    }

    public function test_the_report_reuses_the_alerts_with_the_new_states_and_labels(): void
    {
        $persona = $this->persona();
        Colegiatura::factory()->create(['usuario_id' => $persona->id, 'vigente_hasta' => $this->dia(-3), 'anulado' => false]);
        RequerimientoDocumento::factory()->create(['usuario_id' => $persona->id, 'estado' => 'abierto', 'fecha_limite' => $this->dia(-2)]);

        $filas = collect($this->getJson('/api/v1/reportes/vencimientos?dias=30&exportar=1')->assertOk()->json('data'))->keyBy('origen');
        $this->assertSame(['vencido', 'Vencido'], [$filas['Colegiatura / habilitación']['estado_codigo'], $filas['Colegiatura / habilitación']['estado']]);
        $this->assertSame(['fuera_de_plazo', 'Requerimiento fuera de plazo'], [$filas['Requerimiento']['estado_codigo'], $filas['Requerimiento']['estado']]);
    }
}
