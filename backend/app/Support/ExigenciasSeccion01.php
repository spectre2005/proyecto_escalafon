<?php

namespace App\Support;

use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\TipoDocumento;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Exigencias de la sección 01: por tipo de documento (PerfilDocumental: régimen y banderas) y, para los
 * documentos de familiares, por familiar registrado (sin anulados).
 * - g) partida de matrimonio o constancia de concubinato: si hay cónyuge o concubino/a registrado.
 * - i) DNI del cónyuge o concubino/a: uno por cada cónyuge o concubino/a registrado.
 * - j) DNI de los hijos menores de edad: uno por cada hijo/a menor de 18 años, según su fecha de nacimiento.
 *   Sin fecha no se puede determinar (exigido = null). Al cumplir 18 años deja de exigirse; el documento se conserva.
 * Sin familiares registrados, g, i y j dependen de tiene_derechohabientes (AplicacionDocumental): Sí, por determinar
 * con aviso «falta registrar familiares»; No, no aplican; Sin responder, por determinar.
 * TODO PENDIENTE de confirmar con el cliente (ESTADO_PROYECTO.md, pregunta 18): la directiva no condiciona g, i ni j
 * a una bandera; antes dependían solo de los familiares registrados.
 *
 * El resumen no es la fórmula de completitud del legajo (decisión abierta del cliente): entrega cargados y
 * recepcionados por separado y no cuenta como pendiente lo que no se exige.
 */
final class ExigenciasSeccion01
{
    public const MAYORIA_EDAD = 18;

    /** @return array{edad: ?int, menor_de_edad: ?bool} */
    public static function edad(Familiar $familiar, CarbonInterface $hoy): array
    {
        $nacimiento = $familiar->fecha_nacimiento;
        if ($nacimiento === null) {
            return ['edad' => null, 'menor_de_edad' => null];
        }

        return ['edad' => (int) $nacimiento->diffInYears($hoy), 'menor_de_edad' => $nacimiento->copy()->addYears(self::MAYORIA_EDAD)->gt($hoy)];
    }

    /**
     * Exigencia del documento del familiar (i o j), o null si su parentesco no exige ninguno.
     *
     * @return array{codigo: string, exigido: ?bool, motivo: string}|null
     */
    public static function documentoDeFamiliar(Familiar $familiar, CarbonInterface $hoy): ?array
    {
        if (in_array($familiar->parentesco, Seccion01::CONYUGES, true)) {
            return ['codigo' => Seccion01::DNI_CONYUGE, 'exigido' => true, 'motivo' => 'Se exige: cónyuge o concubino/a registrado/a.'];
        }
        if ($familiar->parentesco !== 'hijo') {
            return null;
        }
        ['edad' => $edad, 'menor_de_edad' => $menor] = self::edad($familiar, $hoy);

        return ['codigo' => Seccion01::DNI_HIJOS] + match (true) {
            $menor === null => ['exigido' => null, 'motivo' => 'No se puede determinar: falta la fecha de nacimiento.'],
            $menor => ['exigido' => true, 'motivo' => "Se exige: menor de edad ({$edad} años)."],
            default => ['exigido' => false, 'motivo' => 'No se exige: ya cumplió 18 años. El documento registrado se conserva.'],
        };
    }

    /**
     * Exigencia de un tipo de la sección. g, i y j dependen de los familiares; los demás, del perfil.
     *
     * @param  Collection<int, Familiar>  $familiares  vigentes (sin anulados)
     * @param  list<array{codigo: string, exigido: ?bool}>  $porFamiliar
     * @return array{exigido: ?bool, motivo: string, aplica: bool}
     */
    public static function tipo(TipoDocumento $tipo, PerfilDocumental $perfil, Collection $familiares, array $porFamiliar): array
    {
        if (in_array($tipo->codigo, AplicacionDocumental::DE_DERECHOHABIENTES, true) && $familiares->isEmpty()) {
            // Sin familiares registrados decide tiene_derechohabientes (PENDIENTE pregunta 18).
            $noAplica = $perfil->aplicacion->noAplica($tipo);

            return match (true) {
                $noAplica !== null => ['exigido' => false, 'aplica' => false, 'motivo' => $noAplica],
                $perfil->banderas['tiene_derechohabientes'] === true => ['exigido' => null, 'aplica' => true,
                    'motivo' => 'Por determinar: falta registrar familiares en Familia y derechohabientes.'],
                default => ['exigido' => null, 'aplica' => true, 'motivo' => (string) $perfil->aplicacion->porDefinir($tipo)],
            };
        }
        if ($tipo->codigo === Seccion01::PARTIDA_MATRIMONIO) {
            $hay = $familiares->contains(fn (Familiar $familiar): bool => in_array($familiar->parentesco, Seccion01::CONYUGES, true));

            return ['exigido' => $hay, 'aplica' => true, 'motivo' => $hay ? 'Se exige: hay cónyuge o concubino/a registrado/a.' : 'No se exige: no hay cónyuge ni concubino/a registrado/a.'];
        }
        if (isset(Seccion01::PARENTESCOS[$tipo->codigo])) {
            $exigidos = array_column(array_filter($porFamiliar, fn (array $item): bool => $item['codigo'] === $tipo->codigo), 'exigido');

            return ['aplica' => true] + match (true) {
                in_array(true, $exigidos, true) => ['exigido' => true, 'motivo' => 'Se exige por cada familiar indicado en Familia y derechohabientes.'],
                in_array(null, $exigidos, true) => ['exigido' => null, 'motivo' => 'No se puede determinar: falta la fecha de nacimiento de algún hijo/a.'],
                default => ['exigido' => false, 'motivo' => $tipo->codigo === Seccion01::DNI_HIJOS ? 'No se exige: no hay hijos/as menores de edad registrados.' : 'No se exige: no hay cónyuge ni concubino/a registrado/a.'],
            };
        }

        // e y k: con su bandera en No no aplican (AplicacionDocumental); sin responder quedan por determinar.
        return [...$perfil->exigencia($tipo), 'aplica' => $perfil->aplicacion->noAplica($tipo) === null];
    }

    /**
     * ¿El documento enlazado al registro (o alguno que lo subsana) está cargado o recepcionado?
     *
     * @param  Collection<int, DocumentoUsuario>  $documentos  de la sección
     * @return array{cargado: bool, recepcionado: bool}
     */
    public static function estadoEnlazado(?string $documentoId, Collection $documentos): array
    {
        $cadena = collect();
        $siguiente = $documentoId ? $documentos->firstWhere('id', $documentoId) : null;
        while ($siguiente && ! $cadena->contains('id', $siguiente->id)) {
            $cadena->push($siguiente);
            $siguiente = $documentos->firstWhere('subsana_a_documento_id', $siguiente->id);
        }
        $cargados = $cadena->filter(fn (DocumentoUsuario $documento): bool => in_array($documento->estado, ResumenSeccion02::ESTADOS_CARGADO, true));

        return ['cargado' => $cargados->isNotEmpty(), 'recepcionado' => $cargados->contains(fn (DocumentoUsuario $documento): bool => $documento->fecha_recepcion !== null && $documento->recepcionado_por !== null)];
    }

    /**
     * Conteo de exigencias: una por tipo y, en i y j, una por familiar.
     *
     * @param  list<array{exigido: ?bool, cargado: bool, recepcionado: bool}>  $items
     */
    public static function resumen(array $items): array
    {
        $exigidos = array_values(array_filter($items, fn (array $item): bool => $item['exigido'] === true));
        $cargados = count(array_filter($exigidos, fn (array $item): bool => $item['cargado']));

        return [
            'exigidos' => count($exigidos),
            'pendientes' => count($exigidos) - $cargados,
            'cargados' => $cargados,
            'recepcionados' => count(array_filter($exigidos, fn (array $item): bool => $item['recepcionado'])),
            'por_determinar' => count(array_filter($items, fn (array $item): bool => $item['exigido'] === null)),
            'detalle' => $exigidos,
        ];
    }
}
