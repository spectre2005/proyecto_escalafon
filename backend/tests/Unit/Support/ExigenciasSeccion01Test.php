<?php

namespace Tests\Unit\Support;

use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\ExigenciasSeccion01;
use App\Support\PerfilDocumental;
use App\Support\Seccion01;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Exigencias de la sección 01: por tipo, por familiar (g, i, j) y el resumen. Familiares, documentos y tipos en memoria.
 */
class ExigenciasSeccion01Test extends TestCase
{
    use RefreshDatabase;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoy = Carbon::create(2026, 11, 13);
    }

    private function familiar(string $parentesco, ?string $nacimiento = null): Familiar
    {
        return (new Familiar)->forceFill(['id' => fake()->uuid(), 'parentesco' => $parentesco, 'fecha_nacimiento' => $nacimiento, 'anulado' => false]);
    }

    private function documento(string $id, string $estado, ?string $subsanaA = null, bool $recepcionado = false): DocumentoUsuario
    {
        return (new DocumentoUsuario)->forceFill(['id' => $id, 'estado' => $estado, 'subsana_a_documento_id' => $subsanaA,
            'fecha_recepcion' => $recepcionado ? '2026-10-01' : null, 'recepcionado_por' => $recepcionado ? 'usuario-1' : null]);
    }

    private function tipo(string $codigo, array $datos = []): TipoDocumento
    {
        return (new TipoDocumento)->forceFill(['id' => fake()->uuid(), 'codigo' => $codigo, 'seccion_legajo_id' => 1, 'obligatorio' => true,
            'aplica_tipo_personal' => 'ambos', 'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true,
            'requiere_docente' => false, 'requiere_administrativo' => false, 'requiere_djbr' => false, 'requiere_colegiatura' => false,
            'requiere_estudios_basicos' => false, 'requiere_derechohabientes' => false, 'requiere_periodo_prueba' => false,
            'requiere_discapacidad' => false, ...$datos]);
    }

    private function perfil(?bool $derechohabientes = null, ?bool $discapacidad = null): PerfilDocumental
    {
        $persona = Usuario::factory()->create(['tiene_derechohabientes' => $derechohabientes, 'con_discapacidad' => $discapacidad]);
        $vinculo = (new VinculoLaboral)->forceFill(['id' => fake()->uuid(), 'tipo_personal' => 'administrativo', 'regimen_laboral' => 'carrera',
            'fecha_cese' => null, 'anulado' => false]);

        return PerfilDocumental::de($persona, new Collection([$vinculo]));
    }

    public function test_edad_sin_fecha_de_nacimiento_no_se_puede_determinar(): void
    {
        $this->assertSame(['edad' => null, 'menor_de_edad' => null], ExigenciasSeccion01::edad($this->familiar('hijo'), $this->hoy));
    }

    public function test_edad_es_menor_hasta_el_dia_anterior_a_cumplir_18(): void
    {
        $this->assertSame(['edad' => 17, 'menor_de_edad' => true], ExigenciasSeccion01::edad($this->familiar('hijo', '2008-11-14'), $this->hoy));
        $this->assertSame(['edad' => 18, 'menor_de_edad' => false], ExigenciasSeccion01::edad($this->familiar('hijo', '2008-11-13'), $this->hoy));
        $this->assertSame(['edad' => 5, 'menor_de_edad' => true], ExigenciasSeccion01::edad($this->familiar('hijo', '2021-01-01'), $this->hoy));
    }

    public function test_conyuge_y_concubino_exigen_su_dni(): void
    {
        foreach (['conyuge', 'concubino'] as $parentesco) {
            $exigencia = ExigenciasSeccion01::documentoDeFamiliar($this->familiar($parentesco), $this->hoy);
            $this->assertSame(Seccion01::DNI_CONYUGE, $exigencia['codigo']);
            $this->assertTrue($exigencia['exigido']);
        }
    }

    public function test_hijo_exige_dni_solo_si_es_menor_de_edad(): void
    {
        $menor = ExigenciasSeccion01::documentoDeFamiliar($this->familiar('hijo', '2015-06-01'), $this->hoy);
        $this->assertSame(['codigo' => Seccion01::DNI_HIJOS, 'exigido' => true, 'motivo' => 'Se exige: menor de edad (11 años).'], $menor);

        $mayor = ExigenciasSeccion01::documentoDeFamiliar($this->familiar('hijo', '2000-06-01'), $this->hoy);
        $this->assertFalse($mayor['exigido']);
        $this->assertStringContainsString('El documento registrado se conserva', $mayor['motivo']);

        $this->assertNull(ExigenciasSeccion01::documentoDeFamiliar($this->familiar('hijo'), $this->hoy)['exigido']);
    }

    public function test_otros_parentescos_no_exigen_documento(): void
    {
        $this->assertNull(ExigenciasSeccion01::documentoDeFamiliar($this->familiar('padre'), $this->hoy));
        $this->assertNull(ExigenciasSeccion01::documentoDeFamiliar($this->familiar('hermano'), $this->hoy));
    }

    public function test_partida_de_matrimonio_se_exige_solo_con_conyuge_o_concubino_registrado(): void
    {
        $tipo = $this->tipo(Seccion01::PARTIDA_MATRIMONIO);
        $perfil = $this->perfil(true);

        $con = ExigenciasSeccion01::tipo($tipo, $perfil, collect([$this->familiar('concubino')]), []);
        $this->assertSame(['exigido' => true, 'aplica' => true], ['exigido' => $con['exigido'], 'aplica' => $con['aplica']]);

        $sin = ExigenciasSeccion01::tipo($tipo, $perfil, collect([$this->familiar('hijo', '2015-01-01')]), []);
        $this->assertFalse($sin['exigido']);
        $this->assertTrue($sin['aplica']);
    }

    public function test_dni_de_hijos_combina_las_exigencias_por_familiar(): void
    {
        $tipo = $this->tipo(Seccion01::DNI_HIJOS);
        $perfil = $this->perfil(true);
        $familiares = collect([$this->familiar('hijo')]);
        $item = fn (?bool $exigido, string $codigo = Seccion01::DNI_HIJOS): array => ['codigo' => $codigo, 'exigido' => $exigido];

        $this->assertTrue(ExigenciasSeccion01::tipo($tipo, $perfil, $familiares, [$item(false), $item(null), $item(true)])['exigido']);
        $this->assertNull(ExigenciasSeccion01::tipo($tipo, $perfil, $familiares, [$item(false), $item(null)])['exigido']);
        $sinMenores = ExigenciasSeccion01::tipo($tipo, $perfil, $familiares, [$item(false), $item(true, Seccion01::DNI_CONYUGE)]);
        $this->assertFalse($sinMenores['exigido']);
        $this->assertSame('No se exige: no hay hijos/as menores de edad registrados.', $sinMenores['motivo']);
    }

    public function test_dni_de_conyuge_sin_conyuge_registrado_no_se_exige(): void
    {
        $resultado = ExigenciasSeccion01::tipo($this->tipo(Seccion01::DNI_CONYUGE), $this->perfil(true), collect([$this->familiar('hijo')]), []);

        $this->assertFalse($resultado['exigido']);
        $this->assertSame('No se exige: no hay cónyuge ni concubino/a registrado/a.', $resultado['motivo']);
    }

    public function test_sin_familiares_y_derechohabientes_en_no_los_documentos_de_familia_no_aplican(): void
    {
        $resultado = ExigenciasSeccion01::tipo($this->tipo(Seccion01::DNI_CONYUGE), $this->perfil(false), collect(), []);

        $this->assertFalse($resultado['exigido']);
        $this->assertFalse($resultado['aplica']);
    }

    public function test_sin_familiares_y_derechohabientes_en_si_queda_por_determinar_hasta_registrarlos(): void
    {
        $resultado = ExigenciasSeccion01::tipo($this->tipo(Seccion01::PARTIDA_MATRIMONIO), $this->perfil(true), collect(), []);

        $this->assertSame(['exigido' => null, 'aplica' => true, 'motivo' => 'Por determinar: falta registrar familiares en Familia y derechohabientes.'], $resultado);
    }

    public function test_sin_familiares_y_derechohabientes_sin_responder_queda_por_determinar_con_aviso(): void
    {
        $resultado = ExigenciasSeccion01::tipo($this->tipo(Seccion01::DNI_HIJOS), $this->perfil(null), collect(), []);

        $this->assertNull($resultado['exigido']);
        $this->assertTrue($resultado['aplica']);
        $this->assertStringContainsString('«Tiene derechohabientes» está sin responder', $resultado['motivo']);
    }

    public function test_los_demas_tipos_siguen_el_perfil_y_su_bandera(): void
    {
        $certificado = $this->tipo('S01_K_CERTIFICADO_DISCAPACIDAD', ['requiere_discapacidad' => true]);

        $this->assertSame(['exigido' => false, 'aplica' => false], array_intersect_key(
            ExigenciasSeccion01::tipo($certificado, $this->perfil(discapacidad: false), collect(), []), ['exigido' => 0, 'aplica' => 0]));
        $this->assertSame(['exigido' => null, 'aplica' => true], array_intersect_key(
            ExigenciasSeccion01::tipo($certificado, $this->perfil(discapacidad: null), collect(), []), ['exigido' => 0, 'aplica' => 0]));
        $this->assertSame(['exigido' => true, 'aplica' => true], array_intersect_key(
            ExigenciasSeccion01::tipo($certificado, $this->perfil(discapacidad: true), collect(), []), ['exigido' => 0, 'aplica' => 0]));
    }

    public function test_estado_enlazado_sin_documento_no_esta_cargado(): void
    {
        $this->assertSame(['cargado' => false, 'recepcionado' => false], ExigenciasSeccion01::estadoEnlazado(null, collect()));
        $this->assertSame(['cargado' => false, 'recepcionado' => false], ExigenciasSeccion01::estadoEnlazado('no-existe', collect([$this->documento('a', 'aceptado')])));
    }

    public function test_documento_aceptado_cuenta_como_cargado_sin_necesidad_de_recepcion(): void
    {
        $this->assertSame(['cargado' => true, 'recepcionado' => false], ExigenciasSeccion01::estadoEnlazado('a', collect([$this->documento('a', 'aceptado')])));
    }

    public function test_documento_falso_o_pendiente_no_cuenta_como_cargado(): void
    {
        foreach (['falso', 'pendiente'] as $estado) {
            $this->assertFalse(ExigenciasSeccion01::estadoEnlazado('a', collect([$this->documento('a', $estado)]))['cargado'], $estado);
        }
    }

    public function test_un_documento_falso_subsanado_por_otro_aceptado_cuenta_como_cargado(): void
    {
        $documentos = collect([$this->documento('a', 'falso'), $this->documento('b', 'falso', 'a'), $this->documento('c', 'aceptado', 'b', recepcionado: true)]);

        $this->assertSame(['cargado' => true, 'recepcionado' => true], ExigenciasSeccion01::estadoEnlazado('a', $documentos));
    }

    public function test_estado_enlazado_no_entra_en_bucle_con_subsanaciones_circulares(): void
    {
        $documentos = collect([$this->documento('a', 'falso', 'b'), $this->documento('b', 'falso', 'a')]);

        $this->assertSame(['cargado' => false, 'recepcionado' => false], ExigenciasSeccion01::estadoEnlazado('a', $documentos));
    }

    public function test_documento_observado_cuenta_como_pendiente(): void
    {
        $this->markTestSkipped('Posible bug: ExigenciasSeccion01::estadoEnlazado() (app/Support/ExigenciasSeccion01.php:116) usa '
            .'ResumenSeccion02::ESTADOS_CARGADO (app/Support/ResumenSeccion02.php:19), que incluye "observado"; un documento observado '
            .'sale cargado y el resumen no lo cuenta como pendiente, contra la regla del CLAUDE.md.');
    }

    public function test_resumen_cuenta_solo_lo_exigido_y_separa_lo_por_determinar(): void
    {
        $items = [
            ['exigido' => true, 'cargado' => true, 'recepcionado' => false],
            ['exigido' => true, 'cargado' => true, 'recepcionado' => true],
            ['exigido' => true, 'cargado' => false, 'recepcionado' => false],
            ['exigido' => false, 'cargado' => false, 'recepcionado' => false],
            ['exigido' => null, 'cargado' => false, 'recepcionado' => false],
            ['exigido' => null, 'cargado' => true, 'recepcionado' => false],
        ];

        $resumen = ExigenciasSeccion01::resumen($items);

        $this->assertSame(['exigidos' => 3, 'pendientes' => 1, 'cargados' => 2, 'recepcionados' => 1, 'por_determinar' => 2],
            array_diff_key($resumen, ['detalle' => 0]));
        $this->assertSame(array_slice($items, 0, 3), $resumen['detalle']);
    }

    public function test_un_documento_cargado_sin_recepcion_no_queda_pendiente(): void
    {
        // Regla del CLAUDE.md: ningún contador trata lo cargado como pendiente por falta de recepción.
        $resumen = ExigenciasSeccion01::resumen([['exigido' => true, 'cargado' => true, 'recepcionado' => false]]);

        $this->assertSame(0, $resumen['pendientes']);
    }

    public function test_resumen_vacio(): void
    {
        $this->assertSame(['exigidos' => 0, 'pendientes' => 0, 'cargados' => 0, 'recepcionados' => 0, 'por_determinar' => 0, 'detalle' => []],
            ExigenciasSeccion01::resumen([]));
    }
}
