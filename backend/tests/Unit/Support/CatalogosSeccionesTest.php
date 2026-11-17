<?php

namespace Tests\Unit\Support;

use App\Support\Seccion05;
use App\Support\Seccion07;
use App\Support\Seccion09;
use App\Support\Seccion11;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Reglas de qué documento acredita qué registro en las secciones 05, 07, 09 y 11 (Anexo 01 de la directiva). */
class CatalogosSeccionesTest extends TestCase
{
    // --- Sección 05 -------------------------------------------------------------------------------------------

    public function test_s05_cada_tipo_pertenece_a_un_bloque_conocido(): void
    {
        foreach (array_keys(Seccion05::TIPOS_DOCUMENTO) as $codigo) {
            $this->assertContains(Seccion05::bloque($codigo), Seccion05::BLOQUES, $codigo);
        }
        $this->assertNull(Seccion05::bloque('NO_EXISTE'));
    }

    public function test_s05_los_registros_acreditados_existen_en_el_esquema(): void
    {
        $validos = [...Seccion05::TIPOS_LICENCIA, ...Seccion05::TIPOS_GOCE];
        foreach (Seccion05::TIPOS_DOCUMENTO as $codigo => [, $registros]) {
            foreach ($registros ?? [] as $registro) {
                $this->assertContains($registro, $validos, "{$codigo} acredita {$registro}");
            }
        }
    }

    public function test_s05_los_datos_de_salud_son_tipos_de_licencia(): void
    {
        foreach (Seccion05::DATOS_SALUD as $tipo) {
            $this->assertContains($tipo, Seccion05::TIPOS_LICENCIA);
        }
    }

    /** @return array<string, array{?string, ?string, bool}> */
    public static function admisionSeccion05(): array
    {
        return [
            'sin código no restringe' => [null, 'permiso', true],
            'tipo de otra sección no restringe' => ['S02_A_OTRO', 'cualquiera', true],
            'suspensión acredita suspensión' => ['S05_SUSPENSION', 'suspension', true],
            'suspensión no acredita permiso' => ['S05_SUSPENSION', 'permiso', false],
            'descanso médico acredita descanso médico' => ['S05_DESCANSO_MEDICO', 'descanso_medico', true],
            'licencia otorgada no acredita permiso' => ['S05_E_LICENCIA_OTORGADA', 'permiso', false],
            'aprobación de licencia acredita permiso' => ['S05_A_APRUEBA_LICENCIA_PERMISO', 'permiso', true],
            'goce no acredita adelanto' => ['S05_B_GOCE_VACACIONAL', 'adelanto', false],
            'adelanto/postergación acredita postergación' => ['S05_C_ADELANTO_POSTERGACION', 'postergacion', true],
            'rol de vacaciones no tiene registro' => ['S05_D_ROL_VACACIONES', 'goce', false],
            'otros de la universidad no tiene registro' => ['S05_OTROS_UNIVERSIDAD', null, false],
        ];
    }

    #[DataProvider('admisionSeccion05')]
    public function test_s05_admite(?string $codigo, ?string $registro, bool $esperado): void
    {
        $this->assertSame($esperado, Seccion05::admite($codigo, $registro));
    }

    // --- Sección 07 -------------------------------------------------------------------------------------------

    public function test_s07_los_desplazamientos_acreditados_existen_en_el_esquema(): void
    {
        foreach (Seccion07::TIPOS_DOCUMENTO as $codigo => [, , , $tipos]) {
            foreach ($tipos ?? [] as $tipo) {
                $this->assertContains($tipo, Seccion07::TIPOS_DESPLAZAMIENTO, "{$codigo} acredita {$tipo}");
            }
        }
    }

    public function test_s07_admite_desplazamiento(): void
    {
        $this->assertTrue(Seccion07::admiteDesplazamiento('S07_73C_ROTACION', 'rotacion'));
        $this->assertFalse(Seccion07::admiteDesplazamiento('S07_73C_ROTACION', 'destaque'));
        $this->assertTrue(Seccion07::admiteDesplazamiento('S07_73E_ENCARGO', 'encargo_funciones'));
        $this->assertTrue(Seccion07::admiteDesplazamiento('S07_73E_ENCARGO', 'encargo_puesto'));
        $this->assertFalse(Seccion07::admiteDesplazamiento('S07_73E_ENCARGO', 'designacion'));
        // Fuera de 7.3 no se restringe.
        $this->assertTrue(Seccion07::admiteDesplazamiento('S07_71A_GESTION_RENDIMIENTO', 'rotacion'));
        $this->assertTrue(Seccion07::admiteDesplazamiento(null, 'rotacion'));
    }

    public function test_s07_literales(): void
    {
        $this->assertSame('a', Seccion07::literal('S07_71A_GESTION_RENDIMIENTO'));
        $this->assertSame('f', Seccion07::literal('S07_73F_OTROS'));
        $this->assertNull(Seccion07::literal('NO_EXISTE'));
        $this->assertNull(Seccion07::desplazamientos(null));
    }

    public function test_s07_el_ciclo_en_curso_es_el_anio_actual(): void
    {
        Carbon::setTestNow('2031-03-15 10:00:00');
        try {
            $this->assertSame(2031, Seccion07::cicloEnCurso());
        } finally {
            Carbon::setTestNow();
        }
    }

    // --- Sección 09 -------------------------------------------------------------------------------------------

    public function test_s09_bloques_y_literales(): void
    {
        foreach (array_keys(Seccion09::TIPOS_DOCUMENTO) as $codigo) {
            $this->assertContains(Seccion09::bloque($codigo), Seccion09::BLOQUES, $codigo);
        }
        $this->assertSame('otros', Seccion09::bloque('NO_EXISTE'));
        $this->assertSame('c', Seccion09::literal('S09_C_AFILIACION_SINDICATO'));
        $this->assertNull(Seccion09::literal('S09_OTROS_UNIVERSIDAD'));
        $this->assertNull(Seccion09::registros(null));
    }

    public function test_s09_admite(): void
    {
        $this->assertTrue(Seccion09::admite('S09_A_CONTROVERSIAS_INDIVIDUALES', 'individual'));
        $this->assertFalse(Seccion09::admite('S09_A_CONTROVERSIAS_INDIVIDUALES', 'colectiva'));
        $this->assertTrue(Seccion09::admite('S09_C_AFILIACION_SINDICATO', Seccion09::AFILIACION));
        $this->assertFalse(Seccion09::admite('S09_C_AFILIACION_SINDICATO', 'eps'));
        $this->assertFalse(Seccion09::admite('S09_OTROS_UNIVERSIDAD', 'individual'));
        $this->assertTrue(Seccion09::admite(null, 'individual'));
        $this->assertTrue(Seccion09::admite('S05_SUSPENSION', 'individual'));
    }

    public function test_s09_los_tipos_de_controversia_coinciden_con_el_esquema(): void
    {
        foreach (['S09_A_CONTROVERSIAS_INDIVIDUALES', 'S09_B_CONTROVERSIAS_COLECTIVAS'] as $codigo) {
            foreach (Seccion09::registros($codigo) as $tipo) {
                $this->assertContains($tipo, Seccion09::TIPOS_CONTROVERSIA);
            }
        }
    }

    // --- Sección 11 -------------------------------------------------------------------------------------------

    public function test_s11_los_motivos_por_tipo_existen(): void
    {
        foreach (array_keys(Seccion11::TIPOS_DOCUMENTO) as $codigo) {
            foreach (Seccion11::motivos($codigo) ?? [] as $motivo) {
                $this->assertContains($motivo, Seccion11::MOTIVOS_CESE, "{$codigo}: {$motivo}");
            }
        }
    }

    public function test_s11_principal_solo_para_documentos_de_termino(): void
    {
        $this->assertTrue(Seccion11::principal('S11_A_TERMINO_DESIGNACION'));
        $this->assertTrue(Seccion11::principal('S11_X_ACEPTACION_RENUNCIA'));
        $this->assertFalse(Seccion11::principal('S11_F_LIQUIDACION'));
        $this->assertFalse(Seccion11::principal('S11_OTROS_UNIVERSIDAD'));
        $this->assertFalse(Seccion11::principal(null));
        $this->assertFalse(Seccion11::principal('NO_EXISTE'));
    }

    /** @return array<string, array{?string, ?string, ?string, bool}> */
    public static function admisionSeccion11(): array
    {
        return [
            'término de designación con su motivo' => ['S11_A_TERMINO_DESIGNACION', 'termino_designacion', 'designado', true],
            'término de designación con otro motivo' => ['S11_A_TERMINO_DESIGNACION', 'renuncia', 'designado', false],
            'término de vínculo admite cualquier motivo' => ['S11_C_TERMINO_VINCULO', 'jubilacion', 'carrera', true],
            'término de vínculo sin régimen' => ['S11_C_TERMINO_VINCULO', 'renuncia', null, true],
            'aceptación de renuncia con renuncia' => ['S11_X_ACEPTACION_RENUNCIA', 'renuncia', 'carrera', true],
            'aceptación de renuncia con destitución' => ['S11_X_ACEPTACION_RENUNCIA', 'destitucion', 'carrera', false],
            'no renovación CAS en plazo determinado' => ['S11_G_NO_RENOVACION_CAS', 'no_renovacion', 'plazo_determinado', true],
            'no renovación CAS en carrera' => ['S11_G_NO_RENOVACION_CAS', 'no_renovacion', 'carrera', false],
            'no renovación CAS sin régimen' => ['S11_G_NO_RENOVACION_CAS', 'no_renovacion', null, false],
            'complementario con cualquier motivo' => ['S11_D_INFORME_GESTION', 'renuncia', 'carrera', true],
            'otros de la universidad nunca' => ['S11_OTROS_UNIVERSIDAD', 'renuncia', 'carrera', false],
            'sin código' => [null, 'renuncia', 'carrera', false],
            'código desconocido' => ['NO_EXISTE', 'renuncia', 'carrera', false],
        ];
    }

    #[DataProvider('admisionSeccion11')]
    public function test_s11_admite(?string $codigo, ?string $motivo, ?string $regimen, bool $esperado): void
    {
        $this->assertSame($esperado, Seccion11::admite($codigo, $motivo, $regimen));
    }

    public function test_s11_motivo_de_rechazo(): void
    {
        $general = 'No corresponde.';

        $this->assertNull(Seccion11::motivoRechazo('S11_C_TERMINO_VINCULO', 'renuncia', 'carrera', $general));
        $this->assertStringContainsString('sección 02', Seccion11::motivoRechazo('S11_G_NO_RENOVACION_CAS', 'no_renovacion', null, $general));
        $this->assertSame($general, Seccion11::motivoRechazo('S11_G_NO_RENOVACION_CAS', 'renuncia', null, $general));
        $this->assertSame($general, Seccion11::motivoRechazo('S11_G_NO_RENOVACION_CAS', 'no_renovacion', 'carrera', $general));
        $this->assertSame($general, Seccion11::motivoRechazo('S11_A_TERMINO_DESIGNACION', 'renuncia', 'designado', $general));
    }
}
