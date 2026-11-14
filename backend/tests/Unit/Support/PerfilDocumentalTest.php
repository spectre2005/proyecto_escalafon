<?php

namespace Tests\Unit\Support;

use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\PerfilDocumental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Perfil por dos ejes (régimen laboral y banderas) y su cruce con tipos_documento para saber qué se exige.
 */
class PerfilDocumentalTest extends TestCase
{
    use RefreshDatabase;

    private function persona(array $datos = []): Usuario
    {
        return Usuario::factory()->create(['obligado_djbr' => null, 'exige_colegiatura' => null, 'exige_estudios_basicos' => null,
            'tiene_periodo_prueba' => null, 'tiene_derechohabientes' => null, 'con_discapacidad' => null, ...$datos]);
    }

    private function vinculo(array $datos = []): VinculoLaboral
    {
        return (new VinculoLaboral)->forceFill(['id' => fake()->uuid(), 'tipo_personal' => 'administrativo', 'regimen_laboral' => 'carrera',
            'nombre_puesto' => 'Analista', 'fecha_cese' => null, 'anulado' => false, 'obligado_djbr' => null, 'exige_colegiatura' => null,
            'exige_estudios_basicos' => null, 'tiene_periodo_prueba' => null, ...$datos]);
    }

    private function tipo(array $datos = []): TipoDocumento
    {
        return (new TipoDocumento)->forceFill(['id' => fake()->uuid(), 'codigo' => 'PRUEBA_UNITARIA', 'seccion_legajo_id' => 99, 'obligatorio' => true,
            'aplica_tipo_personal' => 'ambos', 'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true,
            'requiere_docente' => false, 'requiere_administrativo' => false, 'requiere_djbr' => false, 'requiere_colegiatura' => false,
            'requiere_estudios_basicos' => false, 'requiere_derechohabientes' => false, 'requiere_periodo_prueba' => false,
            'requiere_discapacidad' => false, ...$datos]);
    }

    private function perfil(?Usuario $persona = null, array $vinculos = []): PerfilDocumental
    {
        return PerfilDocumental::de($persona ?? $this->persona(), new Collection($vinculos));
    }

    public function test_los_regimenes_salen_solo_de_los_vinculos_vigentes_no_anulados(): void
    {
        $perfil = $this->perfil(vinculos: [
            $this->vinculo(['regimen_laboral' => 'carrera']),
            $this->vinculo(['regimen_laboral' => 'designado', 'fecha_cese' => '2025-01-01']),
            $this->vinculo(['regimen_laboral' => 'plazo_determinado', 'anulado' => true]),
            $this->vinculo(['regimen_laboral' => 'carrera']),
        ]);

        $this->assertSame(['carrera'], $perfil->regimenes);
    }

    public function test_sin_vinculo_vigente_el_regimen_queda_sin_definir_y_el_resto_sale_de_la_persona(): void
    {
        $persona = $this->persona(['tipo_personal' => 'docente', 'regimen_laboral' => '276', 'obligado_djbr' => true]);
        $perfil = $this->perfil($persona, [$this->vinculo(['fecha_cese' => '2025-01-01'])]);

        $this->assertSame([], $perfil->regimenes);
        $this->assertSame([], $perfil->regimenesClasificados());
        $this->assertSame(['docente'], $perfil->tiposPersonal);
        $this->assertTrue($perfil->banderas['obligado_djbr']);
        $this->assertTrue($perfil->aplicacion->sinVinculoVigente);
    }

    public function test_tipos_de_personal_y_puestos_salen_de_los_vinculos_vigentes_sin_repetir(): void
    {
        $perfil = $this->perfil(vinculos: [
            $this->vinculo(['tipo_personal' => 'docente', 'nombre_puesto' => 'Profesor']),
            $this->vinculo(['tipo_personal' => 'administrativo', 'nombre_puesto' => 'Profesor']),
            $this->vinculo(['tipo_personal' => 'administrativo', 'nombre_puesto' => null]),
        ]);

        $this->assertSame(['docente', 'administrativo'], $perfil->tiposPersonal);
        $this->assertSame(['Profesor'], $perfil->puestos);
    }

    public function test_regimenes_clasificados_descarta_sin_definir_y_regimenes_no_clasificados(): void
    {
        $perfil = $this->perfil(vinculos: [
            $this->vinculo(['regimen_laboral' => null]),
            $this->vinculo(['regimen_laboral' => '276']),
            $this->vinculo(['regimen_laboral' => 'designado']),
        ]);

        $this->assertSame(['designado'], $perfil->regimenesClasificados());
    }

    public function test_un_tipo_opcional_no_se_exige(): void
    {
        $this->assertSame(['exigido' => false, 'motivo' => 'Opcional: se incorpora solo si existe.'],
            $this->perfil(vinculos: [$this->vinculo()])->exigencia($this->tipo(['obligatorio' => false])));
    }

    public function test_requiere_docente_o_administrativo_segun_el_tipo_de_personal(): void
    {
        $administrativo = $this->perfil(vinculos: [$this->vinculo()]);

        $this->assertSame(['exigido' => false, 'motivo' => 'No se exige: solo para personal docente.'],
            $administrativo->exigencia($this->tipo(['requiere_docente' => true])));
        $this->assertTrue($administrativo->exigencia($this->tipo(['requiere_administrativo' => true]))['exigido']);
    }

    public function test_bandera_en_no_no_se_exige_y_sin_responder_queda_por_determinar(): void
    {
        $tipo = $this->tipo(['requiere_djbr' => true]);

        $this->assertFalse($this->perfil(vinculos: [$this->vinculo(['obligado_djbr' => false])])->exigencia($tipo)['exigido']);
        $this->assertNull($this->perfil(vinculos: [$this->vinculo()])->exigencia($tipo)['exigido']);
        $this->assertTrue($this->perfil(vinculos: [$this->vinculo(['obligado_djbr' => true])])->exigencia($tipo)['exigido']);
    }

    public function test_sin_responder_no_se_convierte_en_no(): void
    {
        $exigencia = $this->perfil($this->persona(['con_discapacidad' => null]), [$this->vinculo()])->exigencia($this->tipo(['requiere_discapacidad' => true]));

        $this->assertNull($exigencia['exigido']);
        $this->assertStringContainsString('sin responder en la ficha de datos personales', $exigencia['motivo']);
    }

    public function test_tipo_restringido_por_regimen_sin_vinculo_vigente_queda_por_determinar(): void
    {
        $exigencia = $this->perfil()->exigencia($this->tipo(['aplica_designado' => false]));

        $this->assertNull($exigencia['exigido']);
        $this->assertStringContainsString('está sin definir', $exigencia['motivo']);
    }

    public function test_tipo_que_no_aplica_al_regimen_no_se_exige(): void
    {
        $soloDesignado = $this->tipo(['aplica_carrera' => false, 'aplica_plazo_determinado' => false]);

        $this->assertSame(['exigido' => false, 'motivo' => 'No se exige para su tipo de vínculo.'],
            $this->perfil(vinculos: [$this->vinculo(['regimen_laboral' => 'carrera'])])->exigencia($soloDesignado));
        $this->assertTrue($this->perfil(vinculos: [$this->vinculo(['regimen_laboral' => 'designado'])])->exigencia($soloDesignado)['exigido']);
    }

    public function test_con_otro_vinculo_vigente_sin_definir_no_se_descarta_el_regimen(): void
    {
        $soloDesignado = $this->tipo(['aplica_carrera' => false, 'aplica_plazo_determinado' => false]);
        $perfil = $this->perfil(vinculos: [$this->vinculo(['regimen_laboral' => 'carrera']), $this->vinculo(['regimen_laboral' => null])]);

        $this->assertNull($perfil->exigencia($soloDesignado)['exigido']);
    }

    public function test_tipo_obligatorio_sin_restricciones_se_exige(): void
    {
        $this->assertSame(['exigido' => true, 'motivo' => 'Se exige según su régimen y banderas.'],
            $this->perfil(vinculos: [$this->vinculo()])->exigencia($this->tipo()));
    }

    public function test_tipo_que_aplica_a_todos_los_regimenes_se_exige_aunque_el_regimen_este_sin_definir(): void
    {
        $this->assertTrue($this->perfil()->exigencia($this->tipo())['exigido']);
    }
}
