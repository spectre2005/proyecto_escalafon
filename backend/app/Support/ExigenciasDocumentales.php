<?php

namespace App\Support;

use App\Models\TipoDocumento;
use Illuminate\Support\Collection;

/** Comparte las condiciones ya utilizadas por las pantallas del legajo. */
final class ExigenciasDocumentales
{
    public static function subseccionAplica(?string $codigo, PerfilDocumental $perfil): bool
    {
        return match ($codigo) {
            '2.4' => $perfil->tienePeriodoPrueba(),
            '7.2' => in_array('carrera', $perfil->regimenesClasificados(), true),
            default => true,
        };
    }

    public static function formacionAplica(TipoDocumento $tipo, array $exigencia): bool
    {
        return ! (($tipo->requiere_estudios_basicos || $tipo->requiere_colegiatura) && $exigencia['exigido'] === false);
    }

    public static function reconocimientoAplica(TipoDocumento $tipo, PerfilDocumental $perfil): bool
    {
        return ! $tipo->requiere_docente || in_array('docente', $perfil->tiposPersonal, true);
    }

    /** @return array{exigido: ?bool, motivo: string, aplica: bool} */
    public static function tipo(TipoDocumento $tipo, PerfilDocumental $perfil, Collection $familiares, array $porFamiliar): array
    {
        $exigencia = $tipo->seccion_legajo_id === 1
            ? ExigenciasSeccion01::tipo($tipo, $perfil, $familiares, $porFamiliar)
            : [...$perfil->exigencia($tipo), 'aplica' => true];
        $aplica = self::subseccionAplica($tipo->subseccionLegajo?->codigo, $perfil) && $exigencia['aplica'];
        if ($tipo->seccion_legajo_id === 3) {
            $aplica = $aplica && self::formacionAplica($tipo, $exigencia);
        }
        if ($tipo->seccion_legajo_id === 8) {
            $aplica = $aplica && self::reconocimientoAplica($tipo, $perfil);
        }

        return [...$exigencia, 'aplica' => $aplica];
    }
}
