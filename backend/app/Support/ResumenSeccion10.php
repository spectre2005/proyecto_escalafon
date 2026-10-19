<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class ResumenSeccion10
{
    public const TIPOS = [
        'S10_REGLAMENTO' => ['10.1', 'Cargo de entrega del Reglamento de Seguridad y Salud en el Trabajo', false, false, false],
        'S10_ACCIDENTES_INCIDENTES' => ['10.1', 'Registro de accidentes de trabajo, enfermedades ocupacionales e incidentes', false, false, false],
        'S10_EXAMEN_OCUPACIONAL' => ['10.1', 'Evidencia de realización del examen médico ocupacional', false, true, false],
        'S10_SIMULACROS' => ['10.1', 'Registro de entrenamiento y simulacros de emergencia', false, false, false],
        'S10_CAPACITACIONES' => ['10.1', 'Lista o constancia de asistencia a capacitaciones de la entidad', false, false, false],
        'S10_SEGURO' => ['10.2', 'Trámites y gestiones de seguro', false, true, false],
        'S10_SUBSIDIOS' => ['10.2', 'Trámites y gestiones de subsidios', false, false, false],
        'S10_DESCANSO_MEDICO' => ['10.2', 'Descansos médicos', true, false, false],
        'S10_EPS' => ['10.2', 'Afiliaciones a Entidades Prestadoras de Salud (EPS)', true, true, true],
    ];

    /** @param Collection<int, array<string, mixed>> $documentos */
    public static function item(array $tipo, Collection $documentos, string $hoy): array
    {
        $config = self::TIPOS[$tipo['codigo']];
        $vigentes = $documentos->filter(fn (array $doc): bool => ! $doc['anulado'] && ! $doc['subsanado']);
        $presentes = $vigentes->filter(fn (array $doc): bool => $doc['archivo_disponible']);
        $validos = $presentes->filter(fn (array $doc): bool => ! in_array($doc['estado_original'], ['pendiente', 'observado', 'falso'], true));
        $actuales = $validos->filter(fn (array $doc): bool => (! $doc['vence_en'] || $doc['vence_en'] >= $hoy)
            && ($tipo['codigo'] !== 'S10_EPS' || ! $doc['fecha_inicio'] || $doc['fecha_inicio'] <= $hoy));
        $estado = $actuales->isNotEmpty() ? 'si' : ($validos->isNotEmpty() && $validos->every(fn (array $doc): bool => $doc['vence_en'] && $doc['vence_en'] < $hoy)
            ? 'vencido' : ($vigentes->isNotEmpty() ? 'pendiente' : 'no'));

        return [...$tipo, 'subseccion' => $config[0], 'periodo' => $config[2], 'vigencia' => $config[3], 'entidad_eps' => $config[4],
            'completos' => $actuales->filter(fn (array $doc): bool => in_array($doc['estado_original'], ['aceptado', 'verificado'], true) && ! empty($doc['fecha_recepcion']))->count(),
            'estado' => $estado, 'cantidad' => $presentes->count(), 'ultima_fecha' => $presentes->max('fecha_emision'),
            'numero_folios' => $vigentes->sum('numero_folios'), 'documentos' => $documentos->values()->all()];
    }

    /** @param Collection<int, array<string, mixed>> $documentos */
    public static function folios(Collection $documentos): array
    {
        $vigentes = $documentos->filter(fn (array $doc): bool => ! $doc['anulado'] && ! $doc['subsanado']);
        $sst = $vigentes->where('subseccion', '10.1')->sum('numero_folios');
        $bienestar = $vigentes->where('subseccion', '10.2')->sum('numero_folios');
        $total = $vigentes->sum('numero_folios');

        return ['sst' => $sst, 'bienestar' => $bienestar, 'total' => $total, 'sin_clasificar' => $total - $sst - $bienestar];
    }
}
