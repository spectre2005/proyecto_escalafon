<?php

namespace App\Support;

use App\Models\Usuario;
use Illuminate\Validation\ValidationException;

/**
 * Registros nuevos que una condición del perfil en No hace incompatibles: la misma regla que ya aplica CargaDocumento
 * a los documentos (AplicacionDocumental::noAplica), ahora también para los registros que esas condiciones controlan.
 *
 * - Solo No bloquea. Sin responder (null) no se interpreta como Sí ni como No: se permite, igual que sus documentos.
 * - Para ofrecer cuenta cualquier vínculo no anulado que diga Sí (AplicacionDocumental::bandera(paraOfrecer: true)).
 * - Lo ya registrado se conserva: se consulta, se corrige y se anula; solo se rechaza crear uno nuevo (o, en un
 *   familiar, marcarlo como derechohabiente con «Tiene derechohabientes» en No).
 *
 * Registros controlados: familiares derechohabientes (tiene_derechohabientes), certificados de discapacidad
 * (con_discapacidad), la declaración jurada de bienes y rentas (obligado_djbr), colegiaturas (exige_colegiatura) y la
 * formación de educación básica (exige_estudios_basicos). El período de prueba lo valida Seccion02Controller.
 */
final class CondicionesRegistro
{
    /** Declaraciones que acredita el tipo S01_E (DJBR). */
    private const DECLARACIONES_DJBR = ['djbr'];

    public static function familiar(string $usuario, bool $esDerechohabiente, string $campo = 'es_derechohabiente'): void
    {
        if ($esDerechohabiente) {
            self::exigir($usuario, 'tiene_derechohabientes', $campo, 'marcar un familiar como derechohabiente');
        }
    }

    public static function certificado(string $usuario, string $campo = 'persona'): void
    {
        self::exigir($usuario, 'con_discapacidad', $campo, 'registrar un certificado de discapacidad');
    }

    public static function declaracion(string $usuario, ?string $tipo, string $campo = 'tipo'): void
    {
        if (in_array($tipo, self::DECLARACIONES_DJBR, true)) {
            self::exigir($usuario, 'obligado_djbr', $campo, 'registrar la declaración jurada de bienes y rentas');
        }
    }

    public static function colegiatura(string $usuario, string $campo = 'numero_colegiatura'): void
    {
        self::exigir($usuario, 'exige_colegiatura', $campo, 'registrar una colegiatura');
    }

    public static function formacion(string $usuario, ?string $nivel, string $campo = 'nivel'): void
    {
        if ($nivel === 'educacion_basica') {
            self::exigir($usuario, 'exige_estudios_basicos', $campo, 'registrar estudios básicos');
        }
    }

    private static function exigir(string $usuario, string $bandera, string $campo, string $accion): void
    {
        $aplicacion = AplicacionDocumental::para(Usuario::query()->findOrFail($usuario));
        if ($aplicacion->bandera($bandera, paraOfrecer: true) !== false) {
            return;
        }
        $pregunta = collect(AplicacionDocumental::REQUIERE)->first(fn (array $regla): bool => $regla[0] === $bandera)[2];
        $donde = in_array($bandera, AplicacionDocumental::PUESTO, true) ? 'el vínculo laboral (sección 02)' : 'la ficha de datos personales';
        throw ValidationException::withMessages([$campo => "No se puede {$accion}: «{$pregunta}» está en No en {$donde}. "
            .'Si corresponde, cámbialo a Sí y vuelve a intentarlo. Lo ya registrado se conserva.']);
    }
}
