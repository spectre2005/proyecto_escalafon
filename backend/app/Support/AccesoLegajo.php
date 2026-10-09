<?php

namespace App\Support;

use App\Models\SeccionLegajo;

final class AccesoLegajo
{
    public const MODELOS = [
        1 => ['Familiar', 'DeclaracionVerificacion', 'CertificadoDiscapacidad'],
        2 => ['VinculoLaboral', 'VinculoAdenda', 'Induccion', 'PeriodoPrueba', 'PerfilDocente', 'PerfilAdministrativo'],
        3 => ['FormacionAcademica', 'Capacitacion', 'Colegiatura'],
        4 => ['ExperienciaLaboral'], 5 => ['LicenciaPermiso', 'PeriodoVacacional', 'GoceVacacional'],
        6 => ['CompensacionBeneficio', 'TiempoServicioReconocido'],
        7 => ['EvaluacionDesempeno', 'ProgresionCarrera', 'RatificacionDocente', 'Desplazamiento'],
        8 => ['Reconocimiento', 'Sancion', 'RecursoImpugnatorio'],
        9 => ['Afiliacion', 'Controversia'], 10 => ['RegistroSstBienestar'],
        11 => ['Desvinculacion'], 13 => ['ReferenciaLegajoPasivo'],
    ];

    public static function secciones(): array
    {
        return SeccionLegajo::query()->orderBy('orden')->get()->map(fn ($s): array => [
            'id' => $s->id, 'nombre' => $s->nombre, 'descripcion' => $s->descripcion,
            'activa' => (bool) $s->activa, 'disponibilidad' => $s->disponibilidad,
            'restriccion' => $s->id === 9 ? 'Solo afiliación de sindicato' : null,
        ])->all();
    }

    public static function version(): string
    {
        return hash('sha256', json_encode(self::secciones(), JSON_THROW_ON_ERROR));
    }
}
