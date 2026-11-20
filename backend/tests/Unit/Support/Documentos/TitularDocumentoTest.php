<?php

namespace Tests\Unit\Support\Documentos;

use App\Support\Documentos\TitularDocumento;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TitularDocumentoTest extends TestCase
{
    private const PERSONA = ['dni' => '45678912', 'nombres' => 'María Elena', 'apellido_paterno' => 'Quispe', 'apellido_materno' => 'Huamán'];

    /** @return array<string, array{string, string}> */
    public static function casos(): array
    {
        return [
            'DNI propio' => ["CONSTANCIA\nOtorgada a doña MARIA ELENA QUISPE HUAMAN, identificada con DNI N° 45678912.", 'coincide'],
            'DNI propio con puntos' => ['Titular: Q. H. M., D.N.I. 45678912', 'coincide'],
            'documento nacional de identidad' => ['con Documento Nacional de Identidad N.º 45678912', 'coincide'],
            'solo el nombre, con tildes distintas' => ['Se reconoce a QUISPE HUAMÁN, María Elena por su labor.', 'coincide_nombre'],
            'apellido paterno y primer nombre' => ['El docente Quispe, María ha concluido.', 'coincide_nombre'],
            'otro DNI y otro nombre' => ['Otorgado a JUAN PÉREZ ROJAS, identificado con DNI 40123456.', 'distinto'],
            'otro DNI pero su nombre (error de OCR)' => ['MARIA ELENA QUISPE HUAMAN, DNI 45678918', 'dni_distinto'],
            'su nombre y DNI de terceros (firmante)' => ['A favor de MARIA ELENA QUISPE HUAMAN. Firma: Juan Ruiz, DNI 40123456. Visto: Ana Paz, DNI 40999888.', 'coincide_nombre'],
            'ni DNI ni nombre' => ['RESOLUCIÓN RECTORAL N° 0123-2026-R. Se aprueba el plan anual.', 'sin_datos'],
        ];
    }

    #[DataProvider('casos')]
    public function test_compara_el_documento_con_la_persona(string $texto, string $estado): void
    {
        $this->assertSame($estado, TitularDocumento::comparar($texto, self::PERSONA)['estado']);
    }

    public function test_no_toma_por_dni_numeros_sin_rotulo(): void
    {
        // Resolución, expediente y montos de 8 dígitos no son un DNI.
        $texto = 'Resolución N° 12345678. Expediente 87654321. Monto: 10000000. Otorgada a María Quispe Huamán.';

        $this->assertSame([], TitularDocumento::dnis(mb_strtoupper($texto)));
        $this->assertSame('coincide_nombre', TitularDocumento::comparar($texto, self::PERSONA)['estado']);
    }

    public function test_un_apellido_dentro_de_otra_palabra_no_cuenta(): void
    {
        $persona = ['dni' => '11111111', 'nombres' => 'Ana', 'apellido_paterno' => 'Paz', 'apellido_materno' => 'Lima'];

        $this->assertSame('sin_datos', TitularDocumento::comparar('Comisión de PAZOS y LIMACHI, Anabel.', $persona)['estado']);
    }

    public function test_el_aviso_de_otra_persona_muestra_el_dni_leido(): void
    {
        $resultado = TitularDocumento::comparar('identificado con DNI 40123456 y DNI 40123456', self::PERSONA);

        $this->assertSame(['40123456'], $resultado['dni_leidos']);
        $this->assertStringContainsString('40123456', $resultado['mensaje']);
        $this->assertStringContainsString('otra persona', $resultado['mensaje']);
    }

    public function test_si_aparece_su_dni_coincide_aunque_haya_otros(): void
    {
        $texto = 'Titular DNI 45678912. Jefe inmediato: Carlos Ruiz, DNI 09876543.';

        $this->assertSame('coincide', TitularDocumento::comparar($texto, self::PERSONA)['estado']);
    }

    public function test_sin_apellido_paterno_no_afirma_que_coincida_el_nombre(): void
    {
        $this->assertSame('sin_datos', TitularDocumento::comparar('María Huamán', [...self::PERSONA, 'apellido_paterno' => ''])['estado']);
    }
}
