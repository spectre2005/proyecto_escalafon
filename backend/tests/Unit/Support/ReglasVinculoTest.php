<?php

namespace Tests\Unit\Support;

use App\Support\RegimenesLaborales;
use App\Support\ReglasVinculo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** Reglas sin consultas a la base (se excluyen las reglas exists de los catálogos). */
class ReglasVinculoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Errores del vínculo solo en los campos sin regla exists. */
    private function erroresVinculo(array $datos): array
    {
        $reglas = array_filter(ReglasVinculo::VINCULO, fn (string $regla): bool => ! str_contains($regla, 'exists:'));
        $base = ['tipo_personal' => 'docente', 'regimen_laboral' => 'carrera', 'nombre_puesto' => 'Docente', 'fecha_inicio' => '2026-01-01'];

        return Validator::make([...$base, ...$datos], $reglas)->errors()->toArray();
    }

    private function erroresPerfil(string $tipo, array $datos): array
    {
        return Validator::make($datos, ReglasVinculo::PERFIL[$tipo])->errors()->toArray();
    }

    public function test_los_tipos_de_vinculo_coinciden_con_regimenes_laborales(): void
    {
        $this->assertStringContainsString('in:'.implode(',', array_keys(RegimenesLaborales::TIPOS_VINCULO)), ReglasVinculo::VINCULO['regimen_laboral']);
    }

    public function test_los_tipos_de_personal_tienen_reglas_de_perfil(): void
    {
        $this->assertStringContainsString('in:'.implode(',', array_keys(ReglasVinculo::PERFIL)), ReglasVinculo::VINCULO['tipo_personal']);
    }

    public function test_atributos_y_campos_excluidos_del_alta_existen_en_las_reglas(): void
    {
        $this->assertSame([], array_diff(array_keys(ReglasVinculo::ATRIBUTOS), array_keys(ReglasVinculo::VINCULO)));
        $this->assertSame([], array_diff(ReglasVinculo::NO_EN_ALTA, array_keys(ReglasVinculo::VINCULO)));
    }

    public function test_un_vinculo_minimo_valido_no_tiene_errores(): void
    {
        $this->assertSame([], $this->erroresVinculo([]));
    }

    public function test_campos_obligatorios_del_vinculo(): void
    {
        $errores = Validator::make([], array_filter(ReglasVinculo::VINCULO, fn (string $r): bool => ! str_contains($r, 'exists:')))->errors();
        foreach (['tipo_personal', 'regimen_laboral', 'nombre_puesto', 'fecha_inicio'] as $campo) {
            $this->assertTrue($errores->has($campo), $campo);
        }
        $this->assertFalse($errores->has('fecha_fin_prevista'));
        $this->assertFalse($errores->has('obligado_djbr'));
    }

    public function test_tipo_de_vinculo_rechaza_una_ley(): void
    {
        $this->assertArrayHasKey('regimen_laboral', $this->erroresVinculo(['regimen_laboral' => 'cas']));
        $this->assertArrayHasKey('regimen_laboral', $this->erroresVinculo(['regimen_laboral' => '276']));
    }

    public function test_fecha_fin_prevista_puede_ser_el_mismo_dia_de_inicio_pero_no_antes(): void
    {
        $this->assertArrayNotHasKey('fecha_fin_prevista', $this->erroresVinculo(['fecha_fin_prevista' => '2026-01-01']));
        $this->assertArrayHasKey('fecha_fin_prevista', $this->erroresVinculo(['fecha_fin_prevista' => '2025-12-31']));
    }

    public function test_fecha_cese_entre_el_inicio_y_hoy_incluidos(): void
    {
        $this->assertArrayNotHasKey('fecha_cese', $this->erroresVinculo(['fecha_cese' => '2026-01-01']));
        $this->assertArrayNotHasKey('fecha_cese', $this->erroresVinculo(['fecha_cese' => '2026-06-15']));
        $this->assertArrayHasKey('fecha_cese', $this->erroresVinculo(['fecha_cese' => '2026-06-16']));
        $this->assertArrayHasKey('fecha_cese', $this->erroresVinculo(['fecha_cese' => '2025-12-31']));
    }

    public function test_fechas_de_seleccion_y_documento_no_pueden_ser_futuras(): void
    {
        foreach (['fecha_resultado_seleccion', 'documento_vinculo_fecha'] as $campo) {
            $this->assertArrayNotHasKey($campo, $this->erroresVinculo([$campo => '2026-06-15']));
            $this->assertArrayHasKey($campo, $this->erroresVinculo([$campo => '2026-06-16']));
        }
    }

    public function test_las_fechas_exigen_formato_anio_mes_dia(): void
    {
        $this->assertArrayHasKey('fecha_inicio', $this->erroresVinculo(['fecha_inicio' => '01/01/2026']));
        $this->assertArrayHasKey('fecha_fin_prevista', $this->erroresVinculo(['fecha_fin_prevista' => '2026-1-5']));
    }

    public function test_banderas_aceptan_nulo_y_rechazan_texto_libre(): void
    {
        $this->assertSame([], $this->erroresVinculo(['obligado_djbr' => null, 'exige_colegiatura' => true, 'tiene_periodo_prueba' => 0]));
        $this->assertArrayHasKey('exige_estudios_basicos', $this->erroresVinculo(['exige_estudios_basicos' => 'tal vez']));
    }

    public function test_longitudes_maximas_del_vinculo(): void
    {
        $this->assertArrayNotHasKey('nombre_puesto', $this->erroresVinculo(['nombre_puesto' => str_repeat('a', 150)]));
        $this->assertArrayHasKey('nombre_puesto', $this->erroresVinculo(['nombre_puesto' => str_repeat('a', 151)]));
        $this->assertArrayHasKey('numero_documento_vinculo', $this->erroresVinculo(['numero_documento_vinculo' => str_repeat('1', 61)]));
    }

    public function test_perfil_docente_exige_condicion(): void
    {
        $this->assertArrayHasKey('condicion_docente', $this->erroresPerfil('docente', []));
        $this->assertSame([], $this->erroresPerfil('docente', ['condicion_docente' => 'contratado', 'dedicacion' => 'tiempo_parcial']));
    }

    public function test_categoria_solo_para_docentes_ordinarios(): void
    {
        $this->assertSame([], $this->erroresPerfil('docente', ['condicion_docente' => 'ordinario', 'categoria' => 'principal']));
        $this->assertArrayHasKey('categoria', $this->erroresPerfil('docente', ['condicion_docente' => 'contratado', 'categoria' => 'principal']));
    }

    public function test_subtipo_solo_para_docentes_extraordinarios(): void
    {
        $this->assertSame([], $this->erroresPerfil('docente', ['condicion_docente' => 'extraordinario', 'subtipo_extraordinario' => 'emerito']));
        $this->assertArrayHasKey('subtipo_extraordinario', $this->erroresPerfil('docente', ['condicion_docente' => 'ordinario', 'subtipo_extraordinario' => 'emerito']));
    }

    public function test_perfil_administrativo_valido_y_valores_fuera_de_catalogo(): void
    {
        $this->assertSame([], $this->erroresPerfil('administrativo', []));
        $this->assertSame([], $this->erroresPerfil('administrativo', ['grupo_ocupacional' => 'tecnico', 'es_cargo_confianza' => true]));
        $errores = $this->erroresPerfil('administrativo', ['grupo_ocupacional' => 'directivo', 'es_cargo_confianza' => 'si']);
        $this->assertArrayHasKey('grupo_ocupacional', $errores);
        $this->assertArrayHasKey('es_cargo_confianza', $errores);
    }
}
