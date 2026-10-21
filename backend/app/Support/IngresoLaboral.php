<?php

namespace App\Support;

use App\Models\ProcesoSeleccion;
use App\Models\VinculoLaboral;

/** Reglas compartidas por el alta manual y la sección 02. Los registros anteriores conservan su clasificación pendiente. */
final class IngresoLaboral
{
    public const MODALIDADES = ['concurso_publico', 'designacion_confianza', 'otro_sustentado'];

    public const CAMPOS_PROCESO = ['proceso_seleccion_id', 'proceso_seleccion', 'anio_proceso', 'fecha_resultado_seleccion'];

    /** @return array{contrato: bool, resolucion: bool} */
    public static function documentos(array $datos): array
    {
        $ley = RegimenesLaborales::ley($datos['regimen_normativo_id'] ?? null);

        return [
            'contrato' => $ley?->codigo === '1057' || in_array($datos['regimen_laboral'] ?? null, ['plazo_determinado', 'plazo_indeterminado'], true),
            'resolucion' => ($datos['tipo_personal'] ?? null) === 'docente' || ($datos['regimen_laboral'] ?? null) === 'carrera' || ($datos['modalidad_ingreso'] ?? null) === 'designacion_confianza' || ($datos['regimen_laboral'] ?? null) === 'designado',
        ];
    }

    /** Completa la referencia desde un proceso elegido, sin reemplazar datos contradictorios enviados por el cliente. */
    public static function completarProceso(array $cambios): array
    {
        if (! empty($cambios['proceso_seleccion_id'])) {
            $proceso = ProcesoSeleccion::query()->find($cambios['proceso_seleccion_id']);
            if ($proceso) {
                $cambios['proceso_seleccion'] = filled($cambios['proceso_seleccion'] ?? null) ? $cambios['proceso_seleccion'] : $proceso->codigo;
                $cambios['anio_proceso'] = $cambios['anio_proceso'] ?? $proceso->anio;
            }
        }

        return $cambios;
    }

    /** @return array<string, list<string>> */
    public static function errores(array $cambios, ?VinculoLaboral $actual = null): array
    {
        $datos = [...($actual?->getRawOriginal() ?? []), ...$cambios];
        $modo = $datos['modalidad_ingreso'] ?? null;
        $errores = self::erroresReferenciaProceso($datos);
        if ($actual && ! $modo && ! $actual->modalidad_ingreso) {
            return $errores;
        }
        if (! in_array($modo, self::MODALIDADES, true)) {
            return [...$errores, 'modalidad_ingreso' => ['Selecciona cómo se produjo la incorporación.']];
        }
        $confianza = $modo === 'designacion_confianza';
        if ($confianza && (($datos['tipo_personal'] ?? null) !== 'administrativo' || ($datos['regimen_laboral'] ?? null) !== 'designado')) {
            $errores['modalidad_ingreso'] = ['La designación de confianza corresponde a personal administrativo con vínculo designado.'];
        }
        if (($datos['regimen_laboral'] ?? null) === 'designado' && ! $confianza) {
            $errores['modalidad_ingreso'] = ['Para un vínculo designado selecciona la modalidad de confianza.'];
        }
        $ley = RegimenesLaborales::ley($datos['regimen_normativo_id'] ?? null);
        if ($ley?->codigo === '1057' && ($datos['regimen_laboral'] ?? null) === 'carrera') {
            $errores['regimen_laboral'] = ['CAS no es carrera. Indica plazo determinado, indeterminado o designado, según el documento.'];
        }
        if (($datos['regimen_laboral'] ?? null) === 'plazo_indeterminado' && ! empty($datos['fecha_fin_prevista'])) {
            $errores['fecha_fin_prevista'] = ['Un vínculo de plazo indeterminado no lleva fecha de fin prevista.'];
        }
        if ($modo === 'otro_sustentado' && blank($datos['sustento_ingreso'] ?? null)) {
            $errores['sustento_ingreso'] = ['Explica la modalidad y el sustento de la incorporación sin concurso.'];
        }
        if ($modo === 'concurso_publico') {
            if (blank($datos['proceso_seleccion'] ?? null)) {
                $errores['proceso_seleccion'] = ['Selecciona una convocatoria o registra su código oficial.'];
            }
            if (empty($datos['anio_proceso'])) {
                $errores['anio_proceso'] = ['Indica el año oficial de la convocatoria.'];
            }
        } else {
            foreach (self::CAMPOS_PROCESO as $campo) {
                if (filled($datos[$campo] ?? null)) {
                    $errores[$campo] = ['Esta modalidad no lleva concurso. Retira la referencia al proceso o revisa la modalidad.'];
                }
            }
        }
        foreach (self::documentos($datos) as $documento => $obligatorio) {
            if ($documento === 'resolucion' && ($datos['tipo_personal'] ?? null) === 'docente' && $actual
                && ! array_intersect(['tipo_personal', 'regimen_laboral', 'regimen_normativo_id', 'modalidad_ingreso', 'numero_resolucion', 'fecha_resolucion'], array_keys($cambios))) {
                $obligatorio = false;
            }
            $numero = 'numero_'.$documento;
            $fecha = 'fecha_'.$documento;
            $declarado = filled($datos[$numero] ?? null) || filled($datos[$fecha] ?? null);
            if (($obligatorio || $declarado) && blank($datos[$numero] ?? null)) {
                $errores[$numero] = ['Registra el número oficial de '.($documento === 'contrato' ? 'contrato.' : 'resolución.')];
            }
            if (($obligatorio || $declarado) && empty($datos[$fecha])) {
                $errores[$fecha] = ['Registra la fecha de emisión del documento.'];
            }
        }

        return $errores;
    }

    /** @return array<string, list<string>> */
    private static function erroresReferenciaProceso(array $datos): array
    {
        $errores = [];
        if (! empty($datos['proceso_seleccion_id'])) {
            $proceso = ProcesoSeleccion::query()->find($datos['proceso_seleccion_id']);
            if (! $proceso || $proceso->regimen_normativo_id !== ($datos['regimen_normativo_id'] ?? null)) {
                $errores['proceso_seleccion_id'] = ['El proceso seleccionado debe pertenecer al régimen normativo del vínculo.'];
            } elseif ($proceso->codigo !== mb_strtoupper(trim($datos['proceso_seleccion'] ?? '')) || $proceso->anio !== (int) ($datos['anio_proceso'] ?? 0)) {
                $errores['proceso_seleccion_id'] = ['El código y el año deben coincidir con la convocatoria seleccionada.'];
            }
        }

        return $errores;
    }

    /** Solo se llama dentro de la transacción del vínculo; una convocatoria se comparte entre sus incorporaciones. */
    public static function vincularProceso(array $cambios, ?VinculoLaboral $actual = null): array
    {
        $datos = [...($actual?->getRawOriginal() ?? []), ...$cambios];
        if (($datos['modalidad_ingreso'] ?? null) !== 'concurso_publico') {
            return $cambios;
        }
        $proceso = ProcesoSeleccion::query()->firstOrCreate([
            'regimen_normativo_id' => $datos['regimen_normativo_id'],
            'anio' => (int) $datos['anio_proceso'],
            'codigo' => mb_strtoupper(trim($datos['proceso_seleccion'])),
        ]);

        return [...$cambios, 'proceso_seleccion_id' => $proceso->id, 'proceso_seleccion' => $proceso->codigo, 'anio_proceso' => $proceso->anio];
    }

    /** @return array<string, list<string>> */
    public static function erroresPerfil(array $vinculo, array $perfil): array
    {
        if (empty($vinculo['modalidad_ingreso']) || ($vinculo['tipo_personal'] ?? null) !== 'administrativo') {
            return [];
        }
        $confianza = ($vinculo['modalidad_ingreso'] ?? null) === 'designacion_confianza';
        if ((bool) ($perfil['es_cargo_confianza'] ?? false) !== $confianza) {
            return ['es_cargo_confianza' => ['La condición de confianza debe coincidir con la modalidad de incorporación del vínculo.']];
        }

        return [];
    }
}
