<?php

namespace App\Support\Documentos;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\LegajoSeccion;
use Illuminate\Validation\ValidationException;

/**
 * Folios correlativos por sección del legajo: el siguiente documento empieza en ultimo_folio + 1 de
 * legajo_secciones, sin huecos ni solapes.
 */
final class Folios
{
    /** Último folio usado: el de legajo_secciones o, si la fila aún no existe, el mayor folio_fin de la sección. */
    public static function ultimo(Legajo $legajo, int $seccion): int
    {
        $fila = LegajoSeccion::query()->where('legajo_id', $legajo->id)->where('seccion_legajo_id', $seccion)->first();

        return $fila ? $fila->ultimo_folio : self::mayorFolio($legajo, $seccion);
    }

    /**
     * Reserva los folios dentro de la transacción en curso. Bloquea la fila del legajo (y la de la sección) para
     * que dos cargas simultáneas no tomen el mismo número. Legajos abiertos antes de que existieran sus 13
     * secciones reciben aquí la fila que les falta.
     */
    public static function reservar(Legajo $legajo, int $seccion, int $inicio, int $fin): LegajoSeccion
    {
        Legajo::query()->whereKey($legajo->id)->lockForUpdate()->first();
        $fila = LegajoSeccion::query()->where('legajo_id', $legajo->id)->where('seccion_legajo_id', $seccion)->lockForUpdate()->first()
            ?? LegajoSeccion::query()->create(['legajo_id' => $legajo->id, 'seccion_legajo_id' => $seccion, 'ultimo_folio' => self::mayorFolio($legajo, $seccion)]);
        $siguiente = $fila->ultimo_folio + 1;
        $solapa = DocumentoUsuario::query()->where('legajo_id', $legajo->id)->where('seccion_legajo_id', $seccion)
            ->where('folio_inicio', '<=', $fin)->where('folio_fin', '>=', $inicio)->exists();
        if ($solapa || $inicio < $siguiente) {
            throw ValidationException::withMessages(['folio_inicio' => "Los folios {$inicio}–{$fin} se solapan con folios ya usados en esta sección. El siguiente folio libre es {$siguiente}."]);
        }
        if ($inicio > $siguiente) {
            throw ValidationException::withMessages(['folio_inicio' => "Los folios de la sección son correlativos y sin huecos: el siguiente folio es {$siguiente}."]);
        }
        $fila->update(['ultimo_folio' => $fin, 'ultima_actualizacion' => now()]);

        return $fila;
    }

    private static function mayorFolio(Legajo $legajo, int $seccion): int
    {
        return (int) DocumentoUsuario::query()->where('legajo_id', $legajo->id)->where('seccion_legajo_id', $seccion)->max('folio_fin');
    }
}
