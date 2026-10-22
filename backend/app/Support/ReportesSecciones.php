<?php

namespace App\Support;

use App\Models\Usuario;
use Carbon\CarbonInterface;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Campos explícitos de los informes del legajo; nunca serializa modelos completos. */
final class ReportesSecciones
{
    public const GRUPOS = [1 => '01 · Información personal y familiar', 2 => '02 · Incorporación', 3 => '03 · Formación académica', 4 => '04 · Experiencia laboral', 5 => '05 · Movimientos del personal', 6 => '06 · Compensaciones y beneficios', 7 => '07 · Evaluación y carrera', 8 => '08 · Reconocimientos y sanciones', 9 => '09 · Afiliaciones', 10 => '10 · SST y bienestar', 11 => '11 · Desvinculación'];

    public const DEFINICIONES = [
        'ficha_personal' => ['titulo' => 'Información personal y contacto', 'seccion' => 1, 'relacion' => null, 'campo_fecha' => 'fecha_nacimiento',
            'campos' => ['fecha_nacimiento' => 'Nacimiento', 'edad' => 'Edad', 'nacionalidad' => 'Nacionalidad', 'estado_civil' => 'Estado civil', 'domicilio' => 'Domicilio', 'telefono' => 'Teléfono', 'email_institucional' => 'Correo electrónico']],
        'familiares' => ['titulo' => 'Familiares y derechohabientes', 'seccion' => 1, 'relacion' => 'familiaresPorUsuario', 'campo_fecha' => 'fecha_nacimiento',
            'campos' => ['parentesco' => 'Parentesco', 'apellidos_nombres' => 'Familiar', 'numero_doc_identidad' => 'Documento del familiar', 'fecha_nacimiento' => 'Nacimiento del familiar', 'es_derechohabiente' => 'Derechohabiente']],
        'antecedentes' => ['titulo' => 'Declaraciones, antecedentes e impedimentos', 'seccion' => 1, 'relacion' => 'declaracionesVerificacionesPorUsuario', 'campo_fecha' => 'fecha',
            'campos' => ['tipo' => 'Tipo de declaración / verificación', 'anio' => 'Año', 'fecha' => 'Fecha del registro', 'resultado' => 'Resultado registrado', 'vigente_hasta' => 'Vigencia declarada']],
        'discapacidad' => ['titulo' => 'Certificados de discapacidad', 'seccion' => 1, 'relacion' => 'certificadosDiscapacidadPorUsuario', 'campo_fecha' => 'fecha_emision',
            'campos' => ['persona' => 'Titular del certificado', 'familiar.apellidos_nombres' => 'Familiar asociado', 'numero_conadis' => 'Número CONADIS', 'fecha_emision' => 'Emisión', 'vigente_hasta' => 'Vigente hasta']],
        'inducciones' => ['titulo' => 'Inducciones del personal', 'seccion' => 2, 'relacion' => 'induccionesPorUsuario', 'campo_fecha' => 'fecha',
            'campos' => ['tipo' => 'Tipo de inducción', 'fecha' => 'Fecha de inducción', 'responsable' => 'Responsable']],
        'periodos_prueba' => ['titulo' => 'Períodos de prueba', 'seccion' => 2, 'relacion' => 'periodosPruebaPorUsuario', 'campo_fecha' => 'fecha_inicio',
            'campos' => ['fecha_inicio' => 'Inicio', 'fecha_fin' => 'Fin', 'resultado' => 'Resultado registrado', 'fecha_resultado' => 'Fecha del resultado']],
        'colegiaturas' => ['titulo' => 'Colegiatura y habilitación profesional', 'seccion' => 3, 'relacion' => 'colegiaturasPorUsuario', 'campo_fecha' => 'fecha_colegiatura',
            'campos' => ['colegio_profesional' => 'Colegio profesional', 'numero_colegiatura' => 'Número de colegiatura', 'fecha_colegiatura' => 'Colegiatura', 'habilitado' => 'Habilitación registrada', 'fecha_verificacion' => 'Última verificación', 'vigente_hasta' => 'Vigente hasta']],
        'experiencia' => ['titulo' => 'Experiencia laboral y profesional', 'seccion' => 4, 'relacion' => 'experienciaLaboralPorUsuario', 'campo_fecha' => 'fecha_inicio',
            'campos' => ['entidad_empresa' => 'Entidad / empresa', 'sector' => 'Sector', 'cargo' => 'Cargo', 'tipo_vinculo' => 'Vínculo declarado', 'fecha_inicio' => 'Inicio', 'fecha_fin' => 'Fin', 'es_ejercicio_profesional' => 'Ejercicio profesional', 'es_docencia_universitaria' => 'Docencia universitaria']],
        'tiempo_servicios' => ['titulo' => 'Tiempo de servicios reconocido', 'seccion' => 6, 'relacion' => 'tiempoServiciosReconocidosPorUsuario', 'campo_fecha' => 'fecha_resolucion',
            'campos' => ['numero_resolucion' => 'Resolución', 'fecha_resolucion' => 'Fecha de resolución', 'anios' => 'Años reconocidos', 'meses' => 'Meses reconocidos', 'dias' => 'Días reconocidos']],
        'beneficios' => ['titulo' => 'Compensaciones y beneficios', 'seccion' => 6, 'relacion' => 'compensacionesBeneficiosPorUsuario', 'campo_fecha' => 'fecha',
            'campos' => ['tipo' => 'Tipo de beneficio', 'concepto' => 'Concepto', 'fecha' => 'Fecha', 'numero_documento' => 'Documento de sustento']],
        'desplazamientos' => ['titulo' => 'Desplazamientos y encargos', 'seccion' => 7, 'relacion' => 'desplazamientosPorUsuario', 'campo_fecha' => 'fecha_inicio',
            'campos' => ['tipo' => 'Tipo de desplazamiento', 'unidadOrigen.nombre' => 'Unidad de origen', 'unidadDestino.nombre' => 'Unidad de destino', 'puesto_destino' => 'Puesto de destino', 'fecha_inicio' => 'Inicio', 'fecha_fin' => 'Fin', 'numero_documento' => 'Documento']],
        'progresiones' => ['titulo' => 'Progresión en la carrera administrativa', 'seccion' => 7, 'relacion' => 'progresionesCarreraPorUsuario', 'campo_fecha' => 'fecha_efectiva',
            'campos' => ['nivel_anterior' => 'Nivel anterior', 'nivel_nuevo' => 'Nuevo nivel', 'fecha_efectiva' => 'Fecha efectiva', 'numero_resolucion' => 'Resolución']],
        'ratificaciones' => ['titulo' => 'Nombramiento, ratificación y promoción docente', 'seccion' => 7, 'relacion' => 'ratificacionesDocentesPorUsuario', 'campo_fecha' => 'fecha_resolucion',
            'campos' => ['tipo' => 'Tipo de acto', 'categoria_anterior' => 'Categoría anterior', 'categoria_nueva' => 'Nueva categoría', 'resultado' => 'Resultado registrado', 'numero_resolucion' => 'Resolución', 'fecha_resolucion' => 'Fecha de resolución', 'fecha_inicio_periodo' => 'Inicio del período', 'fecha_vencimiento_periodo' => 'Vencimiento del período']],
        'reconocimientos' => ['titulo' => 'Reconocimientos y distinciones', 'seccion' => 8, 'relacion' => 'reconocimientosPorUsuario', 'campo_fecha' => 'fecha',
            'campos' => ['tipo' => 'Reconocimiento', 'fecha' => 'Fecha', 'motivo' => 'Motivo', 'otorgado_por' => 'Otorgado por', 'numero_documento' => 'Documento']],
        'sanciones' => ['titulo' => 'Sanciones y situación disciplinaria registrada', 'seccion' => 8, 'relacion' => 'sancionesPorUsuario', 'campo_fecha' => 'fecha_resolucion',
            'campos' => ['tipo' => 'Tipo de sanción', 'numero_resolucion' => 'Resolución', 'fecha_resolucion' => 'Fecha de resolución', 'fecha_inicio' => 'Inicio', 'fecha_fin' => 'Fin', 'dias_suspension' => 'Días de suspensión', 'resultado_recurso' => 'Resultado del recurso registrado', 'registrada_en_rnssc' => 'Registro RNSSC declarado']],
        'controversias' => ['titulo' => 'Controversias y resoluciones', 'seccion' => 8, 'relacion' => 'controversiasPorUsuario', 'campo_fecha' => 'fecha_inicio',
            'campos' => ['tipo' => 'Tipo de controversia', 'instancia' => 'Instancia', 'materia' => 'Materia', 'fecha_inicio' => 'Inicio', 'estado' => 'Situación registrada', 'fecha_resolucion' => 'Resolución', 'resultado' => 'Resultado registrado']],
        'afiliaciones' => ['titulo' => 'Afiliaciones del personal', 'seccion' => 9, 'relacion' => 'afiliacionesPorUsuario', 'campo_fecha' => 'fecha_inicio',
            'campos' => ['tipo' => 'Tipo de afiliación', 'entidad' => 'Entidad', 'numero_afiliacion' => 'Número de afiliación', 'fecha_inicio' => 'Inicio', 'fecha_fin' => 'Fin']],
        'sst_bienestar' => ['titulo' => 'Seguridad, salud y bienestar del personal', 'seccion' => 10, 'relacion' => 'registrosSstBienestarPorUsuario', 'campo_fecha' => 'fecha',
            'campos' => ['tipo' => 'Tipo de registro', 'fecha' => 'Fecha', 'entidad' => 'Entidad', 'numero_documento' => 'Documento', 'estado_tramite' => 'Estado del trámite', 'fecha_inicio' => 'Inicio', 'fecha_fin' => 'Fin', 'fecha_vencimiento' => 'Vencimiento']],
        'desvinculaciones' => ['titulo' => 'Ceses y entrega de cargo', 'seccion' => 11, 'relacion' => 'desvinculacionesPorUsuario', 'campo_fecha' => 'fecha_cese',
            'campos' => ['motivo_cese' => 'Motivo de cese', 'fecha_cese' => 'Cese', 'numero_resolucion' => 'Resolución', 'fecha_entrega_cargo' => 'Entrega de cargo', 'informe_gestion_presentado' => 'Informe de gestión presentado', 'fecha_liquidacion' => 'Liquidación registrada']],
    ];

    public static function catalogo(): array
    {
        $resultado = [];
        foreach (self::DEFINICIONES as $tipo => $definicion) {
            $resultado[$tipo] = ['titulo' => $definicion['titulo'], 'descripcion' => 'Información registrada en la sección '.str_pad((string) $definicion['seccion'], 2, '0', STR_PAD_LEFT).' del legajo.',
                'icono' => 'file', 'seccion_origen' => $definicion['seccion'], 'fecha' => $definicion['campos'][$definicion['campo_fecha']],
                'estados' => $tipo === 'ficha_personal' ? ['registrado' => 'Registrado'] : ['con_archivo' => 'Con archivo asociado', 'sin_archivo' => 'Sin archivo asociado'],
                'ayuda' => 'Solo personal con legajo. Un resultado por '.($tipo === 'ficha_personal' ? 'persona' : 'registro no anulado').'. La unidad y el puesto son los de la ficha actual. Los datos y resultados son los registrados; no constituyen una certificación externa. El período filtra por '.$definicion['campos'][$definicion['campo_fecha']].'. No se suman períodos ni importes ni se presume que un dato ausente sea negativo.'];
        }

        return $resultado;
    }

    public static function puede(Usuario $actor, string $tipo): bool
    {
        $seccion = self::DEFINICIONES[$tipo]['seccion'];

        return $actor->can('legajo.ver') && (! in_array($seccion, [1, 9, 10], true) || $actor->veDatosSensibles())
            && ($seccion !== 7 || $actor->can('seccion07.ver')) && ($tipo !== 'sanciones' || $actor->can('seccion08.sanciones.ver'));
    }

    public static function columnas(string $tipo): array
    {
        $campos = ['nombre' => 'Persona', 'documento' => 'DNI / documento', 'legajo' => 'Legajo', 'tipo_personal' => 'Tipo de personal', 'unidad' => 'Unidad actual',
            ...self::DEFINICIONES[$tipo]['campos']];
        if ($tipo !== 'ficha_personal') {
            $campos['sustento'] = 'Estado del sustento';
        }

        return collect($campos)->map(fn (string $nombre, string $campo): array => ['key' => $campo, 'label' => $nombre,
            'type' => str_starts_with($campo, 'fecha') || $campo === 'vigente_hasta' ? 'date' : (in_array($campo, ['edad', 'anio', 'anios', 'meses', 'dias', 'dias_suspension'], true) ? 'number' : 'text')])->values()->all();
    }

    public static function filas(Usuario $actor, string $tipo, Builder $consulta, Closure $contexto, array $filtros): Generator
    {
        $definicion = self::DEFINICIONES[$tipo];
        $relacion = $definicion['relacion'];
        $consulta->with(['legajos' => fn (Builder|Relation $q): Builder|Relation => $q->orderByDesc('fecha_apertura')->orderBy('id')]);
        if ($relacion) {
            $extras = match ($tipo) {
                'discapacidad' => ['familiar'], 'desplazamientos' => ['unidadOrigen', 'unidadDestino'], default => [],
            };
            $consulta->with([$relacion => fn (Builder|Relation $q): Builder|Relation => $q->where('anulado', false)->with(['documento.tipoDocumento', ...$extras])->orderBy('id')]);
        }
        foreach ($consulta->lazy(100) as $persona) {
            $base = $contexto($persona);
            if ((! empty($filtros['unidad_id']) && $base['unidad_id'] !== $filtros['unidad_id'])) {
                continue;
            }
            $registros = $relacion ? $persona->{$relacion} : collect([$persona]);
            foreach ($registros as $registro) {
                $documento = $relacion ? $registro->documento : null;
                if ($documento && ! $actor->veDatosSensibles() && ($documento->tipoDocumento?->es_dato_sensible || $documento->tipoDocumento?->es_dato_salud)) {
                    continue;
                }
                $fila = [...$base, 'id' => $registro->id, 'legajo' => $persona->legajos->pluck('numero_legajo')->implode(' · '),
                    'fecha' => $registro->{$definicion['campo_fecha']}?->toDateString(), 'estado_codigo' => $documento?->archivo_id ? 'con_archivo' : 'sin_archivo',
                    'estado' => $documento?->archivo_id ? 'Con archivo asociado' : 'Sin archivo asociado', 'sustento' => $documento?->estado ?? 'Sin documento asociado'];
                if ($documento?->legajo_id) {
                    $fila['legajo'] = $persona->legajos->firstWhere('id', $documento->legajo_id)?->numero_legajo ?? $fila['legajo'];
                }
                foreach ($definicion['campos'] as $campo => $etiqueta) {
                    $valor = $campo === 'edad' ? ($persona->fecha_nacimiento && $persona->fecha_nacimiento <= today() ? (int) $persona->fecha_nacimiento->age : null) : data_get($registro, $campo);
                    $fila[$campo] = $valor instanceof CarbonInterface ? $valor->toDateString() : (is_bool($valor) ? ($valor ? 'Sí' : 'No') : $valor);
                }
                if (! empty($filtros['contenido'])) {
                    $texto = mb_strtolower(implode(' ', array_map(fn (string $campo): string => (string) ($fila[$campo] ?? ''), array_keys($definicion['campos']))));
                    if (! str_contains($texto, mb_strtolower(trim($filtros['contenido'])))) {
                        continue;
                    }
                }
                if ($tipo === 'ficha_personal') {
                    unset($fila['estado'], $fila['estado_codigo'], $fila['sustento']);
                    $fila['estado_codigo'] = 'registrado';
                    $fila['estado'] = 'Registrado';
                }
                yield $fila;
            }
        }
    }
}
