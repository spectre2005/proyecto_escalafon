<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\RegimenesLaborales;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La cabecera de la ficha (puesto actual y tipo de vínculo vigente) y las constancias usan la misma situación del vínculo
 * (VigenciaLaboral::situacion): un plazo vencido sin cese no se presenta como vigente en ninguna de las dos. Datos ficticios.
 */
class VigenciaFichaConstanciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $this->travelTo(now('America/Lima')->setDate(2026, 10, 22)->setTime(10, 0));
        $this->actuarComo();
    }

    private function persona(): Usuario
    {
        $persona = Usuario::factory()->create(['nombre_puesto' => null, 'unidad_organica_id' => null]);
        Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'activo']);

        return $persona;
    }

    /** Vínculo con documento de vinculación aceptado, para que también entre en la constancia de trabajo. */
    private function vinculo(Usuario $persona, array $datos): VinculoLaboral
    {
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'regimen_laboral' => 'plazo_determinado', 'fecha_cese' => null, ...$datos]);
        DocumentoUsuario::factory()->create(['usuario_id' => $persona->id, 'legajo_id' => $persona->legajos()->value('id'), 'seccion_legajo_id' => 2,
            'vinculo_laboral_id' => $vinculo->id, 'archivo_id' => ArchivoDocumento::factory()->create(['estado_antivirus' => 'limpio'])->id,
            'tipo_documento_id' => TipoDocumento::factory()->create(['seccion_legajo_id' => 2, 'es_dato_sensible' => false, 'es_dato_salud' => false])->id,
            'created_by' => Usuario::factory(), 'estado' => 'aceptado']);

        return $vinculo;
    }

    private function ficha(Usuario $persona): array
    {
        return $this->getJson('/api/v1/expedientes/'.$persona->id)->assertOk()->json('data');
    }

    private function constancia(Usuario $persona): array
    {
        return $this->getJson('/api/v1/reportes/personales/'.$persona->id.'/constancia-trabajo')->assertOk()->json('data');
    }

    public function test_link_starting_in_the_future_is_not_shown_as_current(): void
    {
        $persona = $this->persona();
        $this->vinculo($persona, ['nombre_puesto' => 'PUESTO FUTURO', 'fecha_inicio' => '2027-01-04']);

        $ficha = $this->ficha($persona);
        $this->assertSame(['PUESTO FUTURO', 0, 'programado', 'Inicio previsto el 04/01/2027'],
            [$ficha['puesto_actual']['nombre_puesto'], $ficha['puesto_actual']['vinculos_vigentes'], $ficha['puesto_actual']['situacion']['codigo'], $ficha['puesto_actual']['situacion']['texto']]);
        $this->assertSame([], $ficha['regimen']['tipos_vinculo']);
        $this->assertSame(RegimenesLaborales::SIN_VIGENTE_DETERMINADO, $ficha['regimen']['mensaje']);

        $constancia = $this->constancia($persona);
        $this->assertSame('programado', $constancia['vinculos'][0]['situacion_codigo']);
        $this->assertSame('No se registra un vínculo laboral sustentado que esté vigente a la fecha de emisión.', $constancia['situacion']);
    }

    public function test_expired_link_without_termination_is_undetermined_in_both(): void
    {
        $persona = $this->persona();
        $this->vinculo($persona, ['nombre_puesto' => 'PLAZO VENCIDO', 'fecha_inicio' => '2024-01-02', 'fecha_fin_prevista' => '2025-12-31']);

        $ficha = $this->ficha($persona);
        $this->assertSame('no_determinada', $ficha['puesto_actual']['situacion']['codigo']);
        $this->assertSame('No determinada: el plazo terminó el 31/12/2025 y no hay cese registrado', $ficha['puesto_actual']['situacion']['texto']);
        $this->assertSame(0, $ficha['puesto_actual']['vinculos_vigentes']);
        $this->assertSame([], $ficha['regimen']['tipos_vinculo']);
        $this->assertSame(RegimenesLaborales::SIN_VIGENTE_DETERMINADO, $ficha['regimen']['mensaje']);

        $constancia = $this->constancia($persona);
        $this->assertSame('no_determinada', $constancia['vinculos'][0]['situacion_codigo']);
        $this->assertSame($ficha['puesto_actual']['situacion']['texto'], $constancia['vinculos'][0]['situacion']);
        $this->assertStringNotContainsString('Mantiene', $constancia['situacion']);
    }

    public function test_link_with_registered_termination_is_concluded_and_leaves_the_header(): void
    {
        $persona = $this->persona();
        $this->vinculo($persona, ['nombre_puesto' => 'CESADO', 'fecha_inicio' => '2020-01-02', 'fecha_cese' => '2025-06-30']);

        $ficha = $this->ficha($persona);
        $this->assertSame([null, null, 0, null], [$ficha['puesto_actual']['nombre_puesto'], $ficha['puesto_actual']['origen'],
            $ficha['puesto_actual']['vinculos_vigentes'], $ficha['puesto_actual']['situacion']]);
        $this->assertSame(RegimenesLaborales::SIN_DEFINIR, $ficha['regimen']['mensaje']);
        $this->assertSame('Concluido el 30/06/2025 (cese registrado)', $this->constancia($persona)['vinculos'][0]['situacion']);
    }

    public function test_header_and_certificate_agree_with_several_links(): void
    {
        $persona = $this->persona();
        $this->vinculo($persona, ['nombre_puesto' => 'VIGENTE ANTIGUO', 'regimen_laboral' => 'carrera', 'fecha_inicio' => '2019-03-01']);
        $this->vinculo($persona, ['nombre_puesto' => 'VENCIDO RECIENTE', 'regimen_laboral' => 'designado', 'fecha_inicio' => '2025-01-02', 'fecha_fin_prevista' => '2025-12-31']);
        $this->vinculo($persona, ['nombre_puesto' => 'CON CESE FUTURO', 'regimen_laboral' => 'plazo_determinado', 'fecha_inicio' => '2024-02-01', 'fecha_cese' => '2026-12-31']);

        $ficha = $this->ficha($persona);
        $constancia = $this->constancia($persona);
        $situaciones = collect($constancia['vinculos'])->pluck('situacion_codigo', 'puesto')->all();

        // La ficha muestra el vigente de inicio más reciente, no el vencido (que empezó después).
        $this->assertSame('CON CESE FUTURO', $ficha['puesto_actual']['nombre_puesto']);
        $this->assertSame('vigente', $ficha['puesto_actual']['situacion']['codigo']);
        $this->assertSame($situaciones[$ficha['puesto_actual']['nombre_puesto']], $ficha['puesto_actual']['situacion']['codigo']);
        // Mismos vigentes en la ficha y en la constancia; el tipo del vencido no se presenta como vigente.
        $this->assertSame(count(array_keys($situaciones, 'vigente', true)), $ficha['puesto_actual']['vinculos_vigentes']);
        $this->assertSame(['VIGENTE ANTIGUO' => 'vigente', 'CON CESE FUTURO' => 'vigente', 'VENCIDO RECIENTE' => 'no_determinada'], $situaciones);
        $this->assertEqualsCanonicalizing(['carrera', 'plazo_determinado'], $ficha['regimen']['tipos_vinculo']);
        $this->assertNull($ficha['regimen']['mensaje']);
        $this->assertSame('Mantiene un vínculo laboral vigente a la fecha de emisión.', $constancia['situacion']);
    }
}
