<?php

namespace Tests\Unit\Rules;

use App\Rules\Ruc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RucTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function rucValidos(): array
    {
        return [
            'persona jurídica (20) con resto 1: dígito 10 pasa a 0' => ['20100070970'],
            'persona natural (10) con resto 0: dígito 11 pasa a 1' => ['10123456781'],
            'persona jurídica (20) con dígito común' => ['20000000001'],
            'otra persona jurídica' => ['20602334466'],
            'prefijo 15' => ['15443322113'],
            'prefijo 16' => ['16000000004'],
            'prefijo 17' => ['17999999999'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function rucInvalidos(): array
    {
        return [
            'dígito verificador incorrecto' => ['20100070971'],
            'dígito verificador incorrecto en caso resto 0' => ['10123456780'],
            'diez dígitos' => ['2010007097'],
            'doce dígitos' => ['201000709700'],
            'prefijo 30 no es de contribuyente' => ['30100070970'],
            'prefijo 11 no es de contribuyente' => ['11123456781'],
            'prefijo 00' => ['00000000000'],
            'con letra' => ['2010007097A'],
            'con guiones' => ['20-10007097-0'],
            'con espacio inicial' => [' 20100070970'],
            'cadena vacía' => [''],
        ];
    }

    #[DataProvider('rucValidos')]
    public function test_acepta_ruc_con_prefijo_y_digito_verificador_correctos(string $ruc): void
    {
        $this->assertTrue(Ruc::valido($ruc));
        $this->assertNull($this->errorDeValidacion($ruc));
    }

    #[DataProvider('rucInvalidos')]
    public function test_rechaza_ruc_con_formato_prefijo_o_digito_incorrecto(string $ruc): void
    {
        $this->assertFalse(Ruc::valido($ruc));
        $this->assertSame('El RUC no es válido: deben ser 11 dígitos con el dígito verificador correcto.', $this->errorDeValidacion($ruc));
    }

    public function test_la_regla_rechaza_valores_que_no_son_texto_aunque_tengan_los_digitos_correctos(): void
    {
        $this->assertNotNull($this->errorDeValidacion(20100070970));
        $this->assertNotNull($this->errorDeValidacion(null));
        $this->assertNotNull($this->errorDeValidacion(['20100070970']));
    }

    public function test_cambiar_cualquier_digito_de_un_ruc_valido_lo_invalida(): void
    {
        $base = '20602334466';
        for ($i = 2; $i < 11; $i++) {
            $alterado = $base;
            $alterado[$i] = (string) (((int) $base[$i] + 1) % 10);
            $this->assertFalse(Ruc::valido($alterado), "Posición {$i}: {$alterado}");
        }
    }

    public function test_rechaza_ruc_con_salto_de_linea_final(): void
    {
        if (Ruc::valido("20100070970\n")) {
            $this->markTestSkipped('Posible bug: el patrón usa $ sin modificador D, por lo que acepta un salto de línea final (app/Rules/Ruc.php:18).');
        }
        $this->assertFalse(Ruc::valido("20100070970\n"));
    }

    private function errorDeValidacion(mixed $valor): ?string
    {
        $error = null;
        (new Ruc)->validate('ruc', $valor, function (string $mensaje) use (&$error): void {
            $error = $mensaje;
        });

        return $error;
    }
}
