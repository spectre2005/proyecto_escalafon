<?php

namespace App\Support;

/**
 * Resumen de la sección 02: estado de cada subsección y conteo de tipos de documento.
 *
 * No es la fórmula de completitud del legajo (decisión abierta del cliente: ¿cuentan los cargados, los
 * recepcionados o solo los verificados?). Por eso se entregan los números por separado, calculados con los
 * documentos reales (documentos_usuario). El estado "completa" de una subsección usa los cargados.
 * TODO pendiente de confirmar con el cliente: si "completa" debe exigir documentos verificados.
 */
final class ResumenSeccion02
{
    /**
     * TODO pendiente de confirmar con el cliente: qué estados cuentan como "cargado". Observado se
     * incluye porque el documento está en el legajo; falso no.
     */
    public const ESTADOS_CARGADO = ['cargado', 'en_fiscalizacion', 'verificado', 'observado'];

    /**
     * @param  list<array{exigido: ?bool, cargado: bool, recepcionado: bool, verificado: bool}>  $tipos
     * @param  string  $datos  ninguno | parcial | completo (datos estructurados de la subsección, sin anulados)
     * @return array{estado: string, exigidos: int, exigidos_cargados: int, exigidos_recepcionados: int, exigidos_verificados: int}
     */
    public static function subseccion(bool $aplica, array $tipos, string $datos): array
    {
        $exigidos = array_filter($tipos, fn (array $tipo): bool => $tipo['exigido'] === true);
        $cargados = count(array_filter($exigidos, fn (array $tipo): bool => $tipo['cargado']));
        $conteo = ['exigidos' => count($exigidos), 'exigidos_cargados' => $cargados,
            'exigidos_recepcionados' => count(array_filter($exigidos, fn (array $tipo): bool => $tipo['recepcionado'])), 'exigidos_verificados' => count(array_filter($exigidos, fn (array $tipo): bool => $tipo['verificado']))];
        $algunDocumento = array_filter($tipos, fn (array $tipo): bool => $tipo['cargado']) !== [];
        $estado = match (true) {
            ! $aplica => 'no_aplica',
            $datos === 'ninguno' && ! $algunDocumento => 'sin_datos',
            $datos === 'completo' && $cargados === count($exigidos) => 'completa',
            default => 'parcial',
        };

        return ['estado' => $estado, ...$conteo];
    }

    /**
     * Conteo de los tipos de las subsecciones que aplican (2.4 no cuenta si la persona no tiene período de prueba).
     *
     * @param  list<array{aplica: bool, tipos: list<array{literal: ?string, nombre: string, exigido: ?bool, cargado: bool, recepcionado: bool, verificado: bool}>}>  $subsecciones
     */
    public static function total(array $subsecciones): array
    {
        $tipos = array_merge(...array_map(fn (array $subseccion): array => $subseccion['aplica'] ? $subseccion['tipos'] : [], $subsecciones ?: [['aplica' => false, 'tipos' => []]]));
        $exigidos = array_values(array_filter($tipos, fn (array $tipo): bool => $tipo['exigido'] === true));

        return [
            'tipos' => count($tipos),
            'pendientes' => count(array_filter($tipos, fn (array $tipo): bool => ! $tipo['cargado'])),
            'exigidos' => array_map(fn (array $tipo): array => ['literal' => $tipo['literal'], 'nombre' => $tipo['nombre'], 'cargado' => $tipo['cargado'], 'recepcionado' => $tipo['recepcionado'], 'verificado' => $tipo['verificado']], $exigidos),
            'exigidos_cargados' => count(array_filter($exigidos, fn (array $tipo): bool => $tipo['cargado'])),
            'exigidos_recepcionados' => count(array_filter($exigidos, fn (array $tipo): bool => $tipo['recepcionado'])),
            'exigidos_verificados' => count(array_filter($exigidos, fn (array $tipo): bool => $tipo['verificado'])),
            'por_determinar' => count(array_filter($tipos, fn (array $tipo): bool => $tipo['exigido'] === null)),
        ];
    }
}
