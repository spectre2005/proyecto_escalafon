<?php

namespace Tests\Unit\Support;

use App\Models\Familiar;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\AplicacionDocumental;
use App\Support\Seccion01;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Aplicabilidad y banderas de tres estados (Sí / No / Sin responder). Los vínculos y los tipos se arman en memoria;
 * la base solo se usa para la persona, su legajo y sus familiares.
 */
class AplicacionDocumentalTest extends TestCase
{
    use RefreshDatabase;

    private const SIN_RESPONDER = ['obligado_djbr' => null, 'exige_colegiatura' => null, 'exige_estudios_basicos' => null,
        'tiene_periodo_prueba' => null, 'tiene_derechohabientes' => null, 'con_discapacidad' => null];

    private function persona(array $datos = []): Usuario
    {
        return Usuario::factory()->create([...self::SIN_RESPONDER, ...$datos]);
    }

    private function vinculo(array $datos = []): VinculoLaboral
    {
        return (new VinculoLaboral)->forceFill(['id' => fake()->uuid(), 'tipo_personal' => 'administrativo', 'regimen_laboral' => 'carrera',
            'fecha_cese' => null, 'anulado' => false, 'obligado_djbr' => null, 'exige_colegiatura' => null,
            'exige_estudios_basicos' => null, 'tiene_periodo_prueba' => null, ...$datos]);
    }

    private function tipo(array $datos = []): TipoDocumento
    {
        return (new TipoDocumento)->forceFill(['id' => fake()->uuid(), 'codigo' => 'PRUEBA_UNITARIA', 'seccion_legajo_id' => 99,
            'aplica_tipo_personal' => 'ambos', 'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true,
            'requiere_djbr' => false, 'requiere_colegiatura' => false, 'requiere_estudios_basicos' => false, 'requiere_derechohabientes' => false,
            'requiere_periodo_prueba' => false, 'requiere_discapacidad' => false, ...$datos]);
    }

    private function aplicacion(Usuario $persona, array $vinculos = []): AplicacionDocumental
    {
        return AplicacionDocumental::de($persona, new Collection($vinculos));
    }

    public function test_combinar_si_gana_si_alguno_dice_si(): void
    {
        $this->assertTrue(AplicacionDocumental::combinar([true, false, null]));
        $this->assertTrue(AplicacionDocumental::combinar([null, true]));
    }

    public function test_combinar_no_solo_si_todos_dicen_no(): void
    {
        $this->assertFalse(AplicacionDocumental::combinar([false]));
        $this->assertFalse(AplicacionDocumental::combinar([false, false]));
    }

    public function test_combinar_queda_sin_responder_con_algun_null_o_sin_valores(): void
    {
        $this->assertNull(AplicacionDocumental::combinar([false, null]));
        $this->assertNull(AplicacionDocumental::combinar([null]));
        $this->assertNull(AplicacionDocumental::combinar([]));
    }

    public function test_sin_vinculo_vigente_usa_las_banderas_de_la_persona_y_avisa(): void
    {
        $persona = $this->persona(['obligado_djbr' => true, 'exige_colegiatura' => false]);
        $aplicacion = $this->aplicacion($persona);

        $this->assertTrue($aplicacion->sinVinculoVigente);
        $this->assertTrue($aplicacion->bandera('obligado_djbr'));
        $this->assertFalse($aplicacion->bandera('exige_colegiatura'));
        $this->assertNull($aplicacion->bandera('tiene_periodo_prueba'));
        $this->assertCount(1, $aplicacion->avisos());
        $this->assertStringContainsString('No tiene un vínculo laboral vigente', $aplicacion->avisos()[0]);
    }

    public function test_con_vinculo_vigente_las_banderas_del_puesto_salen_del_vinculo_y_no_hay_aviso(): void
    {
        $persona = $this->persona(['obligado_djbr' => true]);
        $aplicacion = $this->aplicacion($persona, [$this->vinculo(['obligado_djbr' => false])]);

        $this->assertFalse($aplicacion->sinVinculoVigente);
        $this->assertFalse($aplicacion->bandera('obligado_djbr'));
        $this->assertSame([], $aplicacion->avisos());
    }

    public function test_las_banderas_de_la_persona_salen_siempre_de_usuarios(): void
    {
        $persona = $this->persona(['tiene_derechohabientes' => true, 'con_discapacidad' => false]);
        $aplicacion = $this->aplicacion($persona, [$this->vinculo()]);

        $this->assertTrue($aplicacion->bandera('tiene_derechohabientes'));
        $this->assertTrue($aplicacion->bandera('tiene_derechohabientes', paraOfrecer: true));
        $this->assertFalse($aplicacion->bandera('con_discapacidad'));
    }

    public function test_un_vinculo_cesado_con_si_se_ofrece_pero_no_se_exige(): void
    {
        $aplicacion = $this->aplicacion($this->persona(), [
            $this->vinculo(['exige_colegiatura' => false]),
            $this->vinculo(['exige_colegiatura' => true, 'fecha_cese' => '2025-01-01']),
        ]);

        $this->assertFalse($aplicacion->bandera('exige_colegiatura'));
        $this->assertTrue($aplicacion->bandera('exige_colegiatura', paraOfrecer: true));
    }

    public function test_un_vinculo_anulado_no_cuenta_ni_para_exigir_ni_para_ofrecer(): void
    {
        $aplicacion = $this->aplicacion($this->persona(), [
            $this->vinculo(['tiene_periodo_prueba' => false]),
            $this->vinculo(['tiene_periodo_prueba' => true, 'anulado' => true]),
        ]);

        $this->assertFalse($aplicacion->bandera('tiene_periodo_prueba'));
        $this->assertFalse($aplicacion->bandera('tiene_periodo_prueba', paraOfrecer: true));
    }

    public function test_solo_vinculos_anulados_equivale_a_no_tener_vinculo(): void
    {
        $persona = $this->persona(['obligado_djbr' => false]);
        $aplicacion = $this->aplicacion($persona, [$this->vinculo(['obligado_djbr' => true, 'anulado' => true])]);

        $this->assertTrue($aplicacion->sinVinculoVigente);
        $this->assertFalse($aplicacion->bandera('obligado_djbr'));
    }

    public function test_no_aplica_por_tipo_de_personal_sin_distinguir_mayusculas(): void
    {
        $tipo = $this->tipo(['aplica_tipo_personal' => 'docente']);

        $this->assertSame('Solo para personal docente.', $this->aplicacion($this->persona(), [$this->vinculo()])->noAplica($tipo));
        $this->assertNull($this->aplicacion($this->persona(), [$this->vinculo(['tipo_personal' => 'DOCENTE'])])->noAplica($tipo));
    }

    public function test_tipo_para_ambos_o_sin_valor_se_ofrece_a_cualquiera(): void
    {
        $aplicacion = $this->aplicacion($this->persona(), [$this->vinculo()]);

        $this->assertNull($aplicacion->noAplica($this->tipo()));
        $this->assertNull($aplicacion->noAplica($this->tipo(['aplica_tipo_personal' => null])));
    }

    public function test_no_aplica_por_regimen_solo_si_todos_los_regimenes_son_conocidos(): void
    {
        $soloDesignado = $this->tipo(['aplica_carrera' => false, 'aplica_plazo_determinado' => false]);

        $this->assertSame('No corresponde a su régimen laboral.', $this->aplicacion($this->persona(), [$this->vinculo()])->noAplica($soloDesignado));
        $this->assertNull($this->aplicacion($this->persona(), [$this->vinculo(), $this->vinculo(['regimen_laboral' => null])])->noAplica($soloDesignado));
        $this->assertNull($this->aplicacion($this->persona(), [$this->vinculo(['regimen_laboral' => '276'])])->noAplica($soloDesignado));
        $this->assertNull($this->aplicacion($this->persona())->noAplica($soloDesignado));
        $this->assertNull($this->aplicacion($this->persona(), [$this->vinculo(['regimen_laboral' => 'designado', 'fecha_cese' => '2025-01-01'])])->noAplica($soloDesignado));
    }

    public function test_no_aplica_con_bandera_del_puesto_en_no_indica_donde_cambiarla(): void
    {
        $motivo = $this->aplicacion($this->persona(), [$this->vinculo(['obligado_djbr' => false])])->noAplica($this->tipo(['requiere_djbr' => true]));

        $this->assertStringContainsString('está en No', $motivo);
        $this->assertStringContainsString('el vínculo laboral (sección 02)', $motivo);
    }

    public function test_no_aplica_con_bandera_de_la_persona_en_no_indica_la_ficha_personal(): void
    {
        $motivo = $this->aplicacion($this->persona(['con_discapacidad' => false]))->noAplica($this->tipo(['requiere_discapacidad' => true]));

        $this->assertStringContainsString('la ficha de datos personales', $motivo);
    }

    public function test_bandera_sin_responder_o_en_si_no_impide_ofrecer(): void
    {
        $tipo = $this->tipo(['requiere_colegiatura' => true]);

        $this->assertNull($this->aplicacion($this->persona(), [$this->vinculo()])->noAplica($tipo));
        $this->assertNull($this->aplicacion($this->persona(), [$this->vinculo(['exige_colegiatura' => true])])->noAplica($tipo));
    }

    public function test_por_definir_avisa_la_bandera_sin_responder(): void
    {
        $aplicacion = $this->aplicacion($this->persona(), [$this->vinculo()]);

        $this->assertSame('Falta definir si corresponde: «El puesto exige colegiatura» está sin responder en el vínculo laboral (sección 02).',
            $aplicacion->porDefinir($this->tipo(['requiere_colegiatura' => true])));
        $this->assertNull($aplicacion->porDefinir($this->tipo()));
    }

    public function test_por_definir_avisa_el_regimen_sin_vinculo_o_sin_definir(): void
    {
        $soloCarrera = $this->tipo(['aplica_plazo_determinado' => false, 'aplica_designado' => false]);

        $this->assertStringContainsString('aún no hay un vínculo laboral registrado', $this->aplicacion($this->persona())->porDefinir($soloCarrera));
        $this->assertStringContainsString('hay un vínculo con el tipo Sin definir',
            $this->aplicacion($this->persona(), [$this->vinculo(['regimen_laboral' => null])])->porDefinir($soloCarrera));
    }

    public function test_por_definir_es_null_cuando_el_tipo_no_aplica(): void
    {
        $aplicacion = $this->aplicacion($this->persona(), [$this->vinculo(['obligado_djbr' => false])]);

        $this->assertNull($aplicacion->porDefinir($this->tipo(['requiere_djbr' => true])));
    }

    public function test_exigencia_por_bandera_en_tres_estados(): void
    {
        $tipo = $this->tipo(['requiere_periodo_prueba' => true]);

        $no = $this->aplicacion($this->persona(), [$this->vinculo(['tiene_periodo_prueba' => false])])->exigencia($tipo);
        $this->assertSame(['exigido' => false, 'motivo' => 'No se exige: solo si tiene período de prueba.'], $no);

        $sinResponder = $this->aplicacion($this->persona(), [$this->vinculo()])->exigencia($tipo);
        $this->assertNull($sinResponder['exigido']);
        $this->assertStringStartsWith('Falta definir si corresponde', $sinResponder['motivo']);

        $this->assertNull($this->aplicacion($this->persona(), [$this->vinculo(['tiene_periodo_prueba' => true])])->exigencia($tipo));
    }

    public function test_exigencia_es_null_para_un_tipo_sin_banderas(): void
    {
        $this->assertNull($this->aplicacion($this->persona(), [$this->vinculo(['obligado_djbr' => false])])->exigencia($this->tipo()));
    }

    public function test_exigencia_no_se_relaja_por_un_vinculo_cesado_con_si(): void
    {
        $aplicacion = $this->aplicacion($this->persona(), [
            $this->vinculo(['obligado_djbr' => false]),
            $this->vinculo(['obligado_djbr' => true, 'fecha_cese' => '2025-01-01']),
        ]);

        $this->assertFalse($aplicacion->exigencia($this->tipo(['requiere_djbr' => true]))['exigido']);
    }

    public function test_documentos_de_derechohabientes_no_aplican_con_no_y_sin_familiares(): void
    {
        $persona = $this->persona(['tiene_derechohabientes' => false]);
        $tipo = $this->tipo(['seccion_legajo_id' => 1, 'codigo' => Seccion01::DNI_CONYUGE]);

        $this->assertStringContainsString('no hay familiares registrados', $this->aplicacion($persona)->noAplica($tipo));
    }

    public function test_familiares_anulados_no_cuentan_para_derechohabientes(): void
    {
        $persona = $this->persona(['tiene_derechohabientes' => false]);
        Familiar::factory()->create(['usuario_id' => $persona->id, 'parentesco' => 'conyuge', 'anulado' => true, 'registrado_por' => $persona->id]);
        $aplicacion = $this->aplicacion($persona);

        $this->assertFalse($aplicacion->hayFamiliares());
        $this->assertNotNull($aplicacion->noAplica($this->tipo(['seccion_legajo_id' => 1, 'codigo' => Seccion01::DNI_HIJOS])));
    }

    public function test_con_familiares_vigentes_los_documentos_de_derechohabientes_se_ofrecen_aunque_la_bandera_este_en_no(): void
    {
        $persona = $this->persona(['tiene_derechohabientes' => false]);
        Familiar::factory()->create(['usuario_id' => $persona->id, 'parentesco' => 'hijo', 'anulado' => false, 'registrado_por' => $persona->id]);
        $aplicacion = $this->aplicacion($persona);

        $this->assertTrue($aplicacion->hayFamiliares());
        $this->assertNull($aplicacion->noAplica($this->tipo(['seccion_legajo_id' => 1, 'codigo' => Seccion01::DNI_HIJOS])));
    }

    public function test_derechohabientes_sin_responder_y_sin_familiares_queda_por_definir(): void
    {
        $tipo = $this->tipo(['seccion_legajo_id' => 1, 'codigo' => Seccion01::PARTIDA_MATRIMONIO]);

        $this->assertSame('Falta definir si corresponde: «Tiene derechohabientes» está sin responder en la ficha de datos personales.',
            $this->aplicacion($this->persona())->porDefinir($tipo));
    }

    public function test_el_mismo_codigo_en_otra_seccion_no_depende_de_derechohabientes(): void
    {
        $tipo = $this->tipo(['seccion_legajo_id' => 2, 'codigo' => Seccion01::DNI_CONYUGE]);

        $this->assertNull($this->aplicacion($this->persona(['tiene_derechohabientes' => false]))->noAplica($tipo));
    }

    public function test_modulos_ocultos_solo_con_bandera_en_no_y_sin_datos(): void
    {
        $this->assertSame(['s01-familia', 's01-discapacidad'],
            $this->aplicacion($this->persona(['tiene_derechohabientes' => false, 'con_discapacidad' => false]))->modulosOcultos());
        $this->assertSame([], $this->aplicacion($this->persona())->modulosOcultos());
        $this->assertSame([], $this->aplicacion($this->persona(['tiene_derechohabientes' => true, 'con_discapacidad' => true]))->modulosOcultos());

        $conFamilia = $this->persona(['tiene_derechohabientes' => false, 'con_discapacidad' => false]);
        Familiar::factory()->create(['usuario_id' => $conFamilia->id, 'parentesco' => 'hijo', 'anulado' => false, 'registrado_por' => $conFamilia->id]);
        $this->assertSame(['s01-discapacidad'], $this->aplicacion($conFamilia)->modulosOcultos());
    }

    public function test_modulos_ocultos_del_puesto_siguen_la_bandera_para_ofrecer(): void
    {
        $no = ['tiene_periodo_prueba' => false, 'exige_colegiatura' => false];

        $this->assertSame(['s02-prueba', 'colegiaturas'], $this->aplicacion($this->persona(), [$this->vinculo($no)])->modulosOcultosPuesto());
        $this->assertSame([], $this->aplicacion($this->persona(), [$this->vinculo()])->modulosOcultosPuesto());
        // Un vínculo cesado que dijo Sí mantiene visibles los módulos (lo registrado no se esconde).
        $this->assertSame([], $this->aplicacion($this->persona(), [$this->vinculo($no), $this->vinculo(['fecha_cese' => '2025-01-01',
            'tiene_periodo_prueba' => true, 'exige_colegiatura' => true])])->modulosOcultosPuesto());
    }
}
