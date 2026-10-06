<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\ExperienciaLaboral;
use App\Models\Legajo;
use App\Models\ParametroSistema;
use App\Models\Usuario;
use App\Support\TiempoExperiencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TiempoExperienciaTest extends TestCase
{
    use RefreshDatabase;

    private function config(bool $pendientes = false): array
    {
        return ['incluir_pendientes' => $pendientes, 'dias_por_mes' => 30, 'dias_por_anio' => 360, 'hueco_largo_dias' => 90];
    }

    private function periodo(string $inicio, ?string $fin, string $sector = 'publico', string $estado = 'aceptado', string $id = 'uno'): array
    {
        return ['id' => $id, 'fecha_inicio' => $inicio, 'fecha_fin' => $fin, 'sector' => $sector, 'estado' => $estado,
            'es_ejercicio_profesional' => true, 'es_docencia_universitaria' => false];
    }

    public static function casos(): array
    {
        return [
            'un día' => [[['2024-01-01', '2024-01-01']], 1],
            'consecutivos' => [[['2024-01-01', '2024-01-10'], ['2024-01-11', '2024-01-20']], 20],
            'superpuestos' => [[['2024-01-01', '2024-01-10'], ['2024-01-05', '2024-01-15']], 15],
            'contenido en otro' => [[['2024-01-01', '2024-01-31'], ['2024-01-05', '2024-01-10'], ['2024-01-09', '2024-01-15']], 31],
            'hueco' => [[['2024-01-01', '2024-01-10'], ['2024-01-20', '2024-01-25']], 16],
            'abierto' => [[['2024-01-01', null]], 366],
            'bisiesto' => [[['2024-02-28', '2024-03-01']], 3],
            'cruce de año' => [[['2023-12-31', '2024-01-01']], 2],
            'duplicado exacto' => [[['2024-01-01', '2024-01-10'], ['2024-01-01', '2024-01-10']], 10],
        ];
    }

    #[DataProvider('casos')]
    public function test_inclusive_union_counts_each_day_once(array $rangos, int $dias): void
    {
        $periodos = collect($rangos)->map(fn (array $r, int $i): array => $this->periodo($r[0], $r[1], id: (string) $i));
        $resultado = TiempoExperiencia::calcular($periodos, '2024-12-31', $this->config());
        $this->assertSame($dias, $resultado['total']['dias_totales']);
        $this->assertSame(round($dias / 360, 2), $resultado['total']['anios_decimal']);
    }

    public function test_sectors_and_indicators_union_independently(): void
    {
        $a = $this->periodo('2024-01-01', '2024-01-10');
        $b = $this->periodo('2024-01-05', '2024-01-15', 'privado', id: 'dos');
        $b['es_docencia_universitaria'] = true;
        $resultado = TiempoExperiencia::calcular(collect([$a, $b]), '2024-12-31', $this->config());
        $this->assertSame(15, $resultado['total']['dias_totales']);
        $this->assertSame(10, $resultado['publico']['dias_totales']);
        $this->assertSame(11, $resultado['privado']['dias_totales']);
        $this->assertSame(11, $resultado['indicadores']['anios_docente_universitario']['dias_totales']);
        $this->assertSame(15, $resultado['indicadores']['anios_ejercicio_profesional']['dias_totales']);
    }

    public function test_state_filter_is_configurable_and_observed_never_counts(): void
    {
        $p = collect([$this->periodo('2024-01-01', '2024-01-10', estado: 'pendiente'), $this->periodo('2024-02-01', '2024-02-10', estado: 'observado', id: 'dos')]);
        $this->assertSame(0, TiempoExperiencia::calcular($p, '2024-12-31', $this->config())['total']['dias_totales']);
        $this->assertSame(10, TiempoExperiencia::calcular($p, '2024-12-31', $this->config(true))['total']['dias_totales']);
    }

    public function test_large_gap_is_strictly_greater_than_90_and_has_no_trailing_gap(): void
    {
        $a = $this->periodo('2024-01-01', '2024-01-01');
        $b = $this->periodo('2024-04-01', '2024-04-01', id: 'dos');
        $r = TiempoExperiencia::calcular(collect([$a, $b]), '2024-12-31', $this->config());
        $this->assertCount(1, $r['huecos']);
        $this->assertSame(90, $r['huecos'][0]['dias']);
        $this->assertFalse($r['huecos'][0]['largo']);
        $b['fecha_inicio'] = $b['fecha_fin'] = '2024-04-02';
        $this->assertTrue(TiempoExperiencia::calcular(collect([$a, $b]), '2024-12-31', $this->config())['huecos'][0]['largo']);
    }

    public function test_conversion_uses_parameters_and_keeps_unrounded_value_for_requirements(): void
    {
        $p = collect([$this->periodo('2024-01-01', '2024-12-24')]);
        $r = TiempoExperiencia::calcular($p, '2024-12-31', $this->config());
        $this->assertSame(359, $r['total']['dias_totales']);
        $this->assertSame(0, $r['total']['anios']);
        $this->assertSame(11, $r['total']['meses']);
        $this->assertSame(29, $r['total']['dias']);
        $this->assertSame(1.0, $r['total']['anios_decimal']);
        $this->assertLessThan(1, $r['total']['anios_decimal_sin_redondear']);
        $config = [...$this->config(), 'dias_por_anio' => 365, 'dias_por_mes' => 31];
        $this->assertSame(round(359 / 365, 2), TiempoExperiencia::calcular($p, '2024-12-31', $config)['total']['anios_decimal']);
    }

    public function test_database_filter_excludes_unlinked_annulled_subsaned_wrong_owner_and_infected_evidence(): void
    {
        $persona = Usuario::factory()->create();
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id]);
        $doc = DocumentoUsuario::factory()->create(['usuario_id' => $persona->id, 'legajo_id' => $legajo->id, 'seccion_legajo_id' => 4, 'estado' => 'aceptado', 'archivo_id' => ArchivoDocumento::factory()->create(['estado_antivirus' => 'pendiente'])->id]);
        $p = ExperienciaLaboral::factory()->create(['usuario_id' => $persona->id, 'documento_id' => $doc->id, 'sector' => 'publico', 'fecha_inicio' => '2024-01-01', 'fecha_fin' => '2024-01-10', 'es_ejercicio_profesional' => true, 'es_docencia_universitaria' => false]);
        $this->assertSame(10, TiempoExperiencia::paraPersona($persona->id, '2024-12-31')['total']['dias_totales']);
        $doc->update(['estado' => 'anulado']);
        $this->assertSame(0, TiempoExperiencia::paraPersona($persona->id, '2024-12-31')['total']['dias_totales']);
        $doc->update(['estado' => 'aceptado']);
        $p->update(['anulado' => true]);
        $this->assertSame(0, TiempoExperiencia::paraPersona($persona->id, '2024-12-31')['total']['dias_totales']);
        $p->update(['anulado' => false]);
        $doc->archivo->update(['estado_antivirus' => 'infectado']);
        $this->assertSame(0, TiempoExperiencia::paraPersona($persona->id, '2024-12-31')['total']['dias_totales']);
        $doc->archivo->update(['estado_antivirus' => 'pendiente']);
        $otro = Usuario::factory()->create();
        $doc->update(['usuario_id' => $otro->id]);
        $this->assertSame(0, TiempoExperiencia::paraPersona($persona->id, '2024-12-31')['total']['dias_totales']);
        $doc->update(['usuario_id' => $persona->id]);
        DocumentoUsuario::factory()->create(['subsana_a_documento_id' => $doc->id]);
        $this->assertSame(0, TiempoExperiencia::paraPersona($persona->id, '2024-12-31')['total']['dias_totales']);
        $p->update(['documento_id' => null]);
        $this->assertSame(0, TiempoExperiencia::paraPersona($persona->id, '2024-12-31')['total']['dias_totales']);
    }

    public function test_pending_parameter_and_invalid_or_duplicated_configuration(): void
    {
        ParametroSistema::query()->where('clave', 'exp_incluir_pendientes')->update(['valor' => '1']);
        $this->assertTrue(TiempoExperiencia::configuracion()['incluir_pendientes']);
        ParametroSistema::factory()->create(['clave' => 'exp_dias_por_mes', 'valor' => '0', 'tipo_dato' => 'integer']);
        $this->expectException(ValidationException::class);
        TiempoExperiencia::configuracion();
    }
}
