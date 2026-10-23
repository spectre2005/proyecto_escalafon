<?php

namespace App\Support;

use App\Models\Capacitacion;
use App\Models\DocumentoUsuario;
use App\Models\FormacionAcademica;
use App\Models\Legajo;
use App\Models\RegimenNormativo;
use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\Documentos\EstadoDocumento;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Consultas institucionales de solo lectura; el exportado usa exactamente los mismos filtros y permisos. */
final class ConsultaReportes
{
    public const MAX_EXPORTAR = 10000;

    public const REPORTES = [
        'dotacion' => ['titulo' => 'Dotación por unidad y régimen', 'descripcion' => 'Cuántas personas tienen vínculo abierto y cómo se distribuyen.', 'icono' => 'building',
            'fecha' => null, 'estados' => ['docente' => 'Docentes', 'administrativo' => 'Administrativos'],
            'ayuda' => 'Personas con vínculo no anulado y sin cese, según el criterio vigente del sistema. Cada persona se cuenta una vez en su vínculo abierto más reciente. No equivale a plazas presupuestadas, CAP, PAP ni a una certificación de vigencia contractual.'],
        'contratos' => ['titulo' => 'Contratos y adendas', 'descripcion' => 'Vínculos laborales, plazos y ampliaciones para planificar renovaciones.', 'icono' => 'file',
            'fecha' => 'Inicio del vínculo', 'estados' => ['abierto' => 'Abierto', 'plazo_vencido' => 'Plazo vencido sin cese', 'cesado' => 'Con cese registrado', 'programado' => 'Inicio futuro'],
            'ayuda' => 'Un resultado por vínculo no anulado. Se usa la fecha final de la última adenda no anulada por fecha de inicio e identificador; si no existe, el plazo original. La unidad corresponde al vínculo. Un plazo vencido no se interpreta automáticamente como cese.'],
        'movimientos' => ['titulo' => 'Ingresos y ceses', 'descripcion' => 'Incorporaciones y salidas registradas durante un período.', 'icono' => 'arrow',
            'fecha' => 'Fecha del movimiento', 'estados' => ['ingreso' => 'Ingreso', 'cese' => 'Cese'],
            'ayuda' => 'Un ingreso por vínculo no anulado y un cese por su fecha de cese. Si existe una desvinculación no anulada, aporta la resolución y el motivo. Se incluyen también desvinculaciones sin vínculo; se evita duplicar el mismo cese. La unidad es la del vínculo, no la actual de la persona.'],
        'formacion' => ['titulo' => 'Formación y grados académicos', 'descripcion' => 'Títulos, especialidades y grados registrados del personal.', 'icono' => 'folder',
            'fecha' => 'Fecha de obtención', 'estados' => ['con_archivo' => 'Con archivo', 'sin_archivo' => 'Sin archivo'],
            'ayuda' => 'Un resultado por estudio no anulado, con su nivel y sustento documental. Tener un archivo no acredita por sí solo su validez ni una verificación en SUNEDU. Los registros sin fecha se excluyen al filtrar un período.'],
        'capacitaciones' => ['titulo' => 'Capacitaciones del personal', 'descripcion' => 'Participaciones, entidades y horas de formación registradas.', 'icono' => 'chart',
            'fecha' => 'Fecha de finalización', 'estados' => ['con_archivo' => 'Con archivo', 'sin_archivo' => 'Sin archivo'],
            'ayuda' => 'Un resultado por capacitación no anulada. Las horas son las declaradas en el registro; los valores ausentes o negativos no se suman. No mide ejecución presupuestal ni cumplimiento del PDP: el sistema no registra esas metas.'],
        'vacaciones' => ['titulo' => 'Vacaciones por período', 'descripcion' => 'Derechos registrados, goces y adelantos de cada período.', 'icono' => 'clock',
            'fecha' => null, 'estados' => ['con_movimientos' => 'Con goce o adelanto', 'sin_movimientos' => 'Sin goce ni adelanto'],
            'ayuda' => 'Un resultado por período no anulado. Se separan goce, adelanto y postergación; esta última no consume días. Los totales reflejan registros y pueden incluir programación futura. No se calcula saldo legal, deuda vacacional ni liquidación.'],
        'licencias' => ['titulo' => 'Licencias y permisos', 'descripcion' => 'Ausencias autorizadas que coinciden con el período consultado.', 'icono' => 'file',
            'fecha' => 'Período de licencia o permiso', 'estados' => ['con_goce' => 'Con goce', 'sin_goce' => 'Sin goce', 'sin_definir' => 'Goce sin definir'],
            'ayuda' => 'Registros no anulados que se superponen con las fechas consultadas, aunque empiecen antes del período. Los días son los registrados para la licencia completa; no se prorratean. Se excluyen descansos médicos y documentos sensibles cuando la sesión no puede consultarlos. No equivale a control de asistencia ni a un cálculo de descuentos.'],
        'evaluaciones' => ['titulo' => 'Seguimiento de evaluaciones', 'descripcion' => 'Evaluaciones registradas y planes de mejora por ciclo.', 'icono' => 'check',
            'fecha' => 'Fecha de evaluación', 'estados' => ['con_plan' => 'Con plan de mejora', 'sin_plan' => 'Sin plan de mejora'],
            'ayuda' => 'Evaluaciones no anuladas del ciclo seleccionado. No presume que todo el personal está obligado a evaluarse ni calcula una cobertura sin padrón de elegibles. No incluye puntajes ni calificaciones.'],
        'pendientes' => ['titulo' => 'Pendientes documentales', 'descripcion' => 'Qué falta cargar, recibir o revisar en los legajos activos.', 'icono' => 'file',
            'fecha' => null, 'estados' => ['faltante' => 'Falta documento', 'por_determinar' => 'Por determinar', 'observado' => 'Observado', 'recepcion' => 'Sin recepción', 'revision' => 'En revisión'],
            'ayuda' => 'Los requisitos por determinar se muestran aparte de los faltantes. Se excluyen los tipos no aplicables, desactivados y las versiones subsanadas.'],
        'inventario' => ['titulo' => 'Inventario de legajos', 'descripcion' => 'Expedientes, ubicación y documentos registrados.', 'icono' => 'folder',
            'fecha' => 'Fecha de apertura', 'estados' => ['activo' => 'Activo', 'pasivo' => 'Pasivo', 'transferido' => 'Transferido'],
            'ayuda' => 'Un resultado por legajo. Los documentos y folios incluyen su historial, excluyen anulados y se limitan a la información que puedes consultar.'],
        'vencimientos' => ['titulo' => 'Vencimientos', 'descripcion' => 'Documentos, contratos, requerimientos y otros datos con fecha que necesitan seguimiento.', 'icono' => 'clock',
            'fecha' => 'Fecha de vencimiento', 'estados' => ['vencido' => 'Vencido', 'fuera_de_plazo' => 'Requerimiento fuera de plazo', 'por_vencer' => 'Por vencer'],
            'ayuda' => 'Se reutiliza la consulta de Vencimientos: DNI, contratos y adendas (también vencidos sin cese), requerimientos, colegiatura, período de prueba y, según tus permisos, certificado de discapacidad, examen médico ocupacional, ratificación docente y encargos o destaques.'],
        'personal' => ['titulo' => 'Padrón del personal con legajo', 'descripcion' => 'Personal docente y administrativo, unidad, puesto y situación laboral.', 'icono' => 'users',
            'fecha' => null, 'estados' => ['con_vinculo' => 'Con vínculo abierto', 'sin_vinculo' => 'Sin vínculo abierto'],
            'ayuda' => 'Un resultado por persona. El puesto y la unidad proceden del vínculo abierto más reciente; sin vínculo, se identifica el dato histórico de la ficha. Solo incluye personas con al menos un legajo; excluye cuentas técnicas.'],
    ];

    public static function catalogo(): array
    {
        return [...self::REPORTES, ...ReportesSecciones::catalogo()];
    }

    public static function puede(Usuario $actor, string $tipo): bool
    {
        if (isset(ReportesSecciones::DEFINICIONES[$tipo])) {
            return ReportesSecciones::puede($actor, $tipo);
        }

        return $actor->can(in_array($tipo, ['personal', 'dotacion'], true) ? 'personal.ver' : 'legajo.ver')
            && ($tipo !== 'evaluaciones' || $actor->can('seccion07.ver'));
    }

    private static function grupo(string $tipo): string
    {
        $seccion = ReportesSecciones::DEFINICIONES[$tipo]['seccion'] ?? match ($tipo) {
            'personal' => 1, 'contratos', 'movimientos' => 2, 'formacion', 'capacitaciones' => 3,
            'vacaciones', 'licencias' => 5, 'evaluaciones' => 7, default => null,
        };

        return $seccion ? ReportesSecciones::GRUPOS[$seccion] : ($tipo === 'dotacion' ? 'Resumen del personal' : 'Control documental');
    }

    public static function opciones(Usuario $actor): array
    {
        $reportes = collect(self::catalogo())->filter(fn (array $reporte, string $tipo): bool => self::puede($actor, $tipo))
            ->map(fn (array $reporte, string $tipo): array => ['id' => $tipo, 'grupo' => self::grupo($tipo), ...$reporte])->values();
        $secciones = SeccionLegajo::query()->whereBetween('id', [1, 11])->orderBy('id')->get(['id', 'nombre'])
            ->filter(fn (SeccionLegajo $seccion): bool => self::seccionVisible($actor, $seccion->id))->values();

        return ['reportes' => $reportes, 'unidades' => UnidadOrganica::query()->orderBy('nombre')->get(['id', 'nombre']), 'secciones' => $secciones,
            'regimenes' => RegimenNormativo::query()->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
            'niveles' => FormacionAcademica::query()->where('anulado', false)->distinct()->orderBy('nivel')->pluck('nivel')->map(fn (string $nivel): array => ['id' => $nivel, 'nombre' => self::etiqueta($nivel)]),
            'modalidades' => Capacitacion::query()->where('anulado', false)->distinct()->orderBy('tipo')->pluck('tipo')->map(fn (string $tipo): array => ['id' => $tipo, 'nombre' => self::etiqueta($tipo)]),
            'maximo_exportacion' => self::MAX_EXPORTAR];
    }

    public static function listar(Usuario $actor, string $tipo, array $filtros): array
    {
        $exportar = (bool) ($filtros['exportar'] ?? false);
        $porPagina = (int) ($filtros['por_pagina'] ?? 20);
        $pagina = (int) ($filtros['page'] ?? 1);
        $inicio = ($pagina - 1) * $porPagina;
        $filas = [];
        $personas = [];
        $estados = [];
        $horas = 0;
        $total = 0;
        foreach (self::filas($actor, $tipo, $filtros) as $fila) {
            if (! empty($filtros['regimen_id']) && ($fila['regimen_id'] ?? null) !== $filtros['regimen_id']) {
                continue;
            }
            if (! empty($filtros['estado']) && $fila['estado_codigo'] !== $filtros['estado']) {
                continue;
            }
            if ((! empty($filtros['desde']) && ($tipo === 'licencias' ? ($fila['fin'] ?? '') : ($fila['fecha'] ?? '')) < $filtros['desde'])
                || (! empty($filtros['hasta']) && (($fila['fecha'] ?? '') === '' || $fila['fecha'] > $filtros['hasta']))) {
                continue;
            }
            foreach ($fila['_personas'] ?? [$fila['usuario_id']] as $personaId) {
                $personas[$personaId] = true;
            }
            unset($fila['_personas']);
            $estados[$fila['estado_codigo']] = ($estados[$fila['estado_codigo']] ?? 0) + ($fila['personas_total'] ?? 1);
            $horas += $fila['horas'] ?? 0;
            if ($exportar && $total >= self::MAX_EXPORTAR) {
                throw ValidationException::withMessages(['exportar' => 'El reporte supera los 10 000 resultados. Aplica más filtros antes de exportar.']);
            }
            if ($exportar || ($total >= $inicio && $total < $inicio + $porPagina)) {
                $filas[] = $fila;
            }
            $total++;
        }

        return ['data' => $filas, 'columnas' => self::columnas($tipo), 'resumen' => ['resultados' => $total, 'personas' => count($personas), 'por_estado' => $estados, 'horas' => $horas],
            'meta' => ['total' => $total, 'current_page' => $exportar ? 1 : $pagina, 'last_page' => $exportar ? 1 : max(1, (int) ceil($total / $porPagina)), 'per_page' => $exportar ? $total : $porPagina,
                'generado_en' => now()->toIso8601String(), 'generado_por' => trim($actor->nombres.' '.$actor->apellido_paterno.' '.$actor->apellido_materno),
                'tipo' => $tipo, 'titulo' => self::catalogo()[$tipo]['titulo'], 'alcance' => self::catalogo()[$tipo]['ayuda'], 'filtros' => array_diff_key($filtros, array_flip(['page', 'por_pagina', 'exportar']))]];
    }

    private static function personas(array $filtros): Builder
    {
        $consulta = Usuario::query()->where('tipo_doc_identidad', '!=', 'sistema')->whereHas('legajos')->with(['unidadOrganica', 'regimenNormativo', 'vinculosLaborales' => fn ($q) => $q->where('anulado', false)->with(['unidadOrganica', 'regimenNormativo'])->orderByDesc('fecha_inicio')->orderByDesc('id')]);
        if (! empty($filtros['q'])) {
            $texto = '%'.mb_strtolower(trim($filtros['q'])).'%';
            $consulta->where(fn (Builder $q) => $q->whereRaw("LOWER(nombres || ' ' || apellido_paterno || ' ' || COALESCE(apellido_materno, '')) LIKE ?", [$texto])
                ->orWhere('numero_doc_identidad', 'like', $texto)->orWhereHas('legajos', fn (Builder $l) => $l->whereRaw('LOWER(numero_legajo) LIKE ?', [$texto])));
        }
        if (! empty($filtros['tipo_personal'])) {
            $consulta->where('tipo_personal', $filtros['tipo_personal']);
        }

        return $consulta->orderBy('apellido_paterno')->orderBy('apellido_materno')->orderBy('nombres')->orderBy('id');
    }

    private static function contexto(Usuario $persona): array
    {
        $vigentes = $persona->vinculosLaborales->whereNull('fecha_cese');
        $actual = $vigentes->first();

        return ['usuario_id' => $persona->id, 'nombre' => trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno),
            'documento' => $persona->numero_doc_identidad, 'tipo_personal' => ucfirst((string) $persona->tipo_personal),
            'unidad_id' => $actual?->unidad_organica_id ?? $persona->unidad_organica_id,
            'unidad' => $actual?->unidadOrganica?->nombre ?? $persona->unidadOrganica?->nombre ?? 'Sin unidad',
            'puesto' => $actual?->nombre_puesto ?? $persona->nombre_puesto ?? 'Sin puesto',
            'origen_puesto' => $actual ? 'Vínculo laboral' : 'Ficha personal (histórico)',
            'regimen_id' => $actual?->regimen_normativo_id ?? $persona->regimen_normativo_id,
            'ley' => $actual?->regimenNormativo?->codigo ?? $persona->regimenNormativo?->codigo ?? $persona->regimen_laboral ?? 'Sin definir',
            'tipo_vinculo' => $actual?->regimen_laboral ? (['carrera' => 'Carrera', 'plazo_determinado' => 'Plazo determinado', 'designado' => 'Designado'][$actual->regimen_laboral] ?? $actual->regimen_laboral) : 'Sin definir',
            'vinculos' => $vigentes->count()];
    }

    private static function filas(Usuario $actor, string $tipo, array $filtros): Generator
    {
        if (isset(ReportesSecciones::DEFINICIONES[$tipo])) {
            yield from ReportesSecciones::filas($actor, $tipo, self::personas($filtros), self::contexto(...), $filtros);

            return;
        }
        if (in_array($tipo, ['dotacion', 'contratos', 'movimientos', 'formacion', 'capacitaciones', 'vacaciones', 'licencias', 'evaluaciones'], true)) {
            yield from self::institucionales($actor, $tipo, $filtros);

            return;
        }
        if ($tipo === 'vencimientos') {
            yield from self::vencimientos($actor, $filtros);

            return;
        }
        $consulta = self::personas($filtros);
        if ($tipo !== 'personal') {
            $consulta->with(['legajos' => fn ($q) => $q->orderByDesc('fecha_apertura')->orderBy('id'),
                'documentosUsuarioPorUsuario' => fn ($q) => $q->where('estado', '!=', 'anulado')->with('tipoDocumento')]);
        }
        if ($tipo === 'pendientes') {
            $consulta->whereHas('legajos', fn ($q) => $q->where('estado_legajo', 'activo'))->with(['familiaresPorUsuario' => fn ($q) => $q->where('anulado', false)]);
        }
        $tipos = $tipo === 'pendientes' ? self::tiposVisibles($actor, $filtros) : collect();
        foreach ($consulta->lazy(100) as $persona) {
            $base = self::contexto($persona);
            if (! empty($filtros['unidad_id']) && $base['unidad_id'] !== $filtros['unidad_id']) {
                continue;
            }
            if ($tipo === 'personal') {
                $estado = $base['vinculos'] ? 'con_vinculo' : 'sin_vinculo';
                yield [...$base, 'id' => $persona->id, 'fecha' => $persona->created_at?->toDateString(), 'estado_codigo' => $estado,
                    'estado' => self::catalogo()['personal']['estados'][$estado], 'cuenta' => $persona->activo ? 'Habilitada' : 'Deshabilitada'];
            } elseif ($tipo === 'inventario') {
                foreach ($persona->legajos as $legajo) {
                    $documentos = $persona->documentosUsuarioPorUsuario->where('legajo_id', $legajo->id)
                        ->filter(fn (DocumentoUsuario $d): bool => $d->archivo_id !== null && $d->tipoDocumento && self::tipoVisible($actor, $d->tipoDocumento));
                    if (! empty($filtros['seccion'])) {
                        $documentos = $documentos->where('seccion_legajo_id', (int) $filtros['seccion']);
                    }
                    $porSeccion = $documentos->groupBy('seccion_legajo_id')->map(fn ($docs): int => $docs->sum(fn ($d): int => max(0, (int) $d->folio_fin - (int) $d->folio_inicio + 1)));
                    yield [...$base, 'id' => $legajo->id, 'legajo' => $legajo->numero_legajo, 'estado_codigo' => $legajo->estado_legajo,
                        'estado' => ucfirst($legajo->estado_legajo), 'formato' => $legajo->formato_legajo ?? 'Sin definir', 'ubicacion' => $legajo->ubicacion ?? 'Sin ubicación',
                        'fecha' => $legajo->fecha_apertura?->toDateString(), 'documentos' => $documentos->count(),
                        'folios' => ! empty($filtros['seccion']) ? ($porSeccion[(int) $filtros['seccion']] ?? 0) : $porSeccion->sum(),
                        'por_seccion' => $porSeccion->map(fn (int $folios, int $seccion): string => str_pad((string) $seccion, 2, '0', STR_PAD_LEFT).': '.$folios)->implode(' · ')];
                }
            } else {
                yield from self::pendientes($actor, $persona, $base, $tipos);
            }
        }
    }

    /** @return Generator<int, array<string, mixed>> */
    public static function pendientesAutomaticos(Usuario $actor): Generator
    {
        foreach (self::filas($actor, 'pendientes', []) as $fila) {
            if (in_array($fila['estado_codigo'], ['faltante', 'observado'], true)) {
                yield $fila;
            }
        }
        foreach (self::filas($actor, 'vencimientos', ['dias' => 0]) as $fila) {
            if ($fila['estado_codigo'] !== 'vencido' || $fila['origen'] !== 'Documento') {
                continue;
            }
            $documento = DocumentoUsuario::query()->find($fila['id']);
            if ($documento && $documento->tipoDocumento->activo && ConfiguracionFuncionalidades::tipoActivo((string) $documento->tipoDocumento->codigo, $documento->seccion_legajo_id)) {
                yield [...$fila, 'legajo_id' => $documento->legajo_id, 'tipo_documento_id' => $documento->tipo_documento_id];
            }
        }
    }

    private static function pendientes(Usuario $actor, Usuario $persona, array $base, Collection $tipos): Generator
    {
        $perfil = PerfilDocumental::de($persona, $persona->vinculosLaborales);
        $familiares = $persona->familiaresPorUsuario;
        $pendientesPerfil = PerfilPendiente::de($persona, $perfil->aplicacion, $actor->veDatosSensibles());
        $porFamiliar = $familiares->map(fn ($f) => ExigenciasSeccion01::documentoDeFamiliar($f, today()))->filter()->values()->all();
        foreach ($persona->legajos->where('estado_legajo', 'activo') as $legajo) {
            $todos = $persona->documentosUsuarioPorUsuario->where('legajo_id', $legajo->id);
            $subsanados = $todos->pluck('subsana_a_documento_id')->filter();
            $actuales = $todos->whereNotIn('id', $subsanados);
            foreach ($tipos as $tipo) {
                if ($perfil->aplicacion->noAplica($tipo) !== null) {
                    continue;
                }
                $exigencia = $tipo->seccion_legajo_id === 1 ? ExigenciasSeccion01::tipo($tipo, $perfil, $familiares, $porFamiliar) : $perfil->exigencia($tipo);
                if (($exigencia['aplica'] ?? true) === false) {
                    continue;
                }
                if ($exigencia['exigido'] === null) {
                    $exigencia['destino_revision'] = self::destinoPerfil($persona, $perfil, $tipo, $pendientesPerfil);
                }
                $propios = $actuales->where('tipo_documento_id', $tipo->id);
                if (isset(Seccion01::PARENTESCOS[$tipo->codigo]) && $familiares->isNotEmpty()) {
                    foreach ($familiares as $familiar) {
                        $exigenciaFamiliar = ExigenciasSeccion01::documentoDeFamiliar($familiar, today());
                        if (! $exigenciaFamiliar || $exigenciaFamiliar['codigo'] !== $tipo->codigo) {
                            continue;
                        }
                        if ($exigenciaFamiliar['exigido'] === null) {
                            $exigenciaFamiliar['destino_revision'] = '/legajos/'.$persona->id.'?categoria=s01-familia#registro-'.$familiar->id;
                        }
                        $documento = $todos->firstWhere('id', $familiar->documento_id);
                        $visitados = [];
                        while ($documento && ! isset($visitados[$documento->id])) {
                            $visitados[$documento->id] = true;
                            $siguiente = $todos->firstWhere('subsana_a_documento_id', $documento->id);
                            if (! $siguiente) {
                                break;
                            }
                            $documento = $siguiente;
                        }
                        yield from self::pendienteTipo($base, $legajo, $tipo, $exigenciaFamiliar, $documento ? collect([$documento]) : collect(), $familiar->apellidos_nombres);
                    }
                } else {
                    yield from self::pendienteTipo($base, $legajo, $tipo, $exigencia, $propios);
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $pendientes */
    private static function destinoPerfil(Usuario $persona, PerfilDocumental $perfil, TipoDocumento $tipo, array $pendientes): string
    {
        $bandera = null;
        foreach (AplicacionDocumental::REQUIERE as $columna => [$campo]) {
            if ($tipo->{$columna} && ($perfil->banderas[$campo] ?? null) === null) {
                $bandera = $campo;
                break;
            }
        }
        if (in_array($tipo->codigo, AplicacionDocumental::DE_DERECHOHABIENTES, true)) {
            if ($perfil->banderas['tiene_derechohabientes'] === null) {
                $bandera = 'tiene_derechohabientes';
            } elseif ($perfil->banderas['tiene_derechohabientes'] === true) {
                return '/legajos/'.$persona->id.'?categoria=s01-familia#bloque-registros';
            }
        }
        foreach ($pendientes as $pendiente) {
            if ($pendiente['bandera'] === $bandera) {
                $categoria = match ($bandera) {
                    'tiene_derechohabientes' => 's01-familia',
                    'con_discapacidad' => 's01-discapacidad',
                    default => 's02-vinculo',
                };

                return '/legajos/'.$persona->id.'?categoria='.$categoria.'#perfil-pendiente-'.$bandera.'-'.($pendiente['vinculo_id'] ?? 'persona');
            }
        }
        $vinculo = $persona->vinculosLaborales->first(fn (VinculoLaboral $v): bool => ! $v->anulado && $v->fecha_cese === null
            && ! in_array($v->regimen_laboral, array_keys(PerfilDocumental::APLICA), true));

        return '/legajos/'.$persona->id.'?categoria=s02-vinculo#'.($vinculo ? 'registro-'.$vinculo->id : 'bloque-registros');
    }

    private static function pendienteTipo(array $base, Legajo $legajo, TipoDocumento $tipo, array $exigencia, Collection $documentos, ?string $familiar = null): Generator
    {
        $comun = [...$base, 'legajo_id' => $legajo->id, 'tipo_documento_id' => $tipo->id, 'legajo' => $legajo->numero_legajo, 'seccion' => str_pad((string) $tipo->seccion_legajo_id, 2, '0', STR_PAD_LEFT),
            'detalle' => $tipo->nombre.($familiar ? ' · '.$familiar : ''), 'fecha' => null];
        $cargados = $documentos->filter(fn ($d): bool => $d->archivo_id !== null && ! in_array($d->estado, ['anulado', 'falso'], true));
        if ($documentos->isEmpty() || $cargados->isEmpty() && ! $documentos->contains('estado', 'observado') && ! $documentos->contains('estado', 'falso')) {
            if ($exigencia['exigido'] !== false) {
                $estado = $exigencia['exigido'] === null ? 'por_determinar' : 'faltante';
                yield [...$comun, 'id' => $legajo->id.':'.$tipo->id.':'.$familiar, 'estado_codigo' => $estado, 'estado' => self::catalogo()['pendientes']['estados'][$estado], 'motivo' => $exigencia['motivo'], 'destino_revision' => $exigencia['destino_revision'] ?? null];
            }

            return;
        }
        foreach ($documentos as $doc) {
            $estado = match (true) {
                in_array($doc->estado, ['observado', 'falso'], true) => 'observado',
                $doc->archivo_id !== null && ! EstadoDocumento::recepcionado($doc) => 'recepcion',
                $doc->archivo_id !== null && ! in_array($doc->estado, ['aceptado', 'verificado', 'recepcionado'], true) && in_array($tipo->seccion_legajo_id, [3, 4, 6, 8, 10], true) => 'revision',
                default => null,
            };
            if ($estado) {
                yield [...$comun, 'id' => $doc->id, 'fecha' => $doc->fecha_emision?->toDateString(), 'estado_codigo' => $estado,
                    'estado' => self::catalogo()['pendientes']['estados'][$estado], 'motivo' => $estado === 'observado' ? 'El documento requiere subsanación o revisión.' : ($estado === 'recepcion' ? 'El archivo está cargado sin recepción registrada.' : 'Documento recibido pendiente de revisión.')];
            }
        }
    }

    private static function vencimientos(Usuario $actor, array $filtros): Generator
    {
        $hoy = CarbonImmutable::now('America/Lima')->startOfDay();
        $consulta = DB::query()->fromSub(ConsultaVencimientos::alertas($actor, $hoy, (int) ($filtros['dias'] ?? 30), null), 'alertas')
            ->orderBy('fecha')->orderBy('usuario_id')->orderBy('alerta_id');
        $pagina = 1;
        do {
            $alertas = (clone $consulta)->forPage($pagina++, 100)->get();
            $personas = self::personas($filtros)->whereIn('id', $alertas->pluck('usuario_id'))->get()->keyBy('id');
            foreach ($alertas as $alerta) {
                $persona = $personas->get($alerta->usuario_id);
                if (! $persona) {
                    continue;
                }
                $base = self::contexto($persona);
                if (! empty($filtros['unidad_id']) && $base['unidad_id'] !== $filtros['unidad_id']) {
                    continue;
                }
                $fecha = substr($alerta->fecha, 0, 10);
                $estado = ConsultaVencimientos::estado($alerta->origen, $fecha, $hoy);
                // Las tres etiquetas de siempre se conservan (pendientesAutomaticos compara «Documento»); las nuevas, de ConsultaVencimientos.
                $origen = ['documento' => 'Documento', 'contrato' => 'Contrato', 'requerimiento' => 'Requerimiento'][$alerta->origen] ?? ConsultaVencimientos::ORIGENES[$alerta->origen];
                yield [...$base, 'id' => $alerta->alerta_id, 'fecha' => $fecha, 'estado_codigo' => $estado,
                    'estado' => self::catalogo()['vencimientos']['estados'][$estado], 'origen' => $origen, 'detalle' => $alerta->detalle,
                    'dias' => (int) $hoy->diffInDays(CarbonImmutable::parse($fecha, 'America/Lima'), false)];
            }
        } while ($alertas->count() === 100);
    }

    private static function institucionales(Usuario $actor, string $tipo, array $filtros): Generator
    {
        $consulta = self::personas(array_diff_key($filtros, ['tipo_personal' => true]));
        $relacion = match ($tipo) {
            'formacion' => 'formacionAcademicaPorUsuario',
            'capacitaciones' => 'capacitacionesPorUsuario',
            'vacaciones' => 'periodosVacacionalesPorUsuario',
            'licencias' => 'licenciasPermisosPorUsuario',
            'evaluaciones' => 'evaluacionesDesempenoPorUsuario',
            default => null,
        };
        if ($tipo === 'vacaciones') {
            $consulta->with('periodosVacacionalesPorUsuario.gocesVacacionales', 'periodosVacacionalesPorUsuario.vinculoLaboral.unidadOrganica', 'periodosVacacionalesPorUsuario.vinculoLaboral.regimenNormativo');
        }
        if (in_array($tipo, ['contratos', 'movimientos'], true)) {
            $consulta->with(['vinculosLaborales.vinculoAdendas' => fn (Builder|Relation $q): Builder|Relation => $q->where('anulado', false)->orderByDesc('fecha_inicio')->orderByDesc('id'),
                'vinculosLaborales.desvinculaciones' => fn (Builder|Relation $q): Builder|Relation => $q->where('anulado', false)->orderByDesc('fecha_cese')->orderByDesc('id'),
                'desvinculacionesPorUsuario' => fn (Builder|Relation $q): Builder|Relation => $q->where('anulado', false)->whereNull('vinculo_laboral_id')->orderBy('fecha_cese')->orderBy('id')]);
        }
        $consulta->with(['vinculosLaborales' => fn (Builder|Relation $q): Builder|Relation => $q->where('anulado', false)->with(['unidadOrganica', 'regimenNormativo'])->orderByDesc('fecha_inicio')->orderByDesc('id')]);
        if ($relacion) {
            $consulta->with([$relacion => fn (Builder|Relation $q): Builder|Relation => $q->where('anulado', false)->with('documento.tipoDocumento')->orderBy('id')]);
        }
        $grupos = [];
        foreach ($consulta->lazy(100) as $persona) {
            $base = self::contexto($persona);
            if ($tipo === 'dotacion') {
                $actual = $persona->vinculosLaborales->whereNull('fecha_cese')->first();
                if ($actual) {
                    $base = self::contextoVinculo($base, $actual);
                }
                if (! $base['vinculos'] || ! self::unidadCoincide($base, $filtros)) {
                    continue;
                }
                $clave = json_encode([$base['unidad_id'], $base['ley'], $base['tipo_personal']], JSON_THROW_ON_ERROR);
                if (! isset($grupos[$clave])) {
                    $grupos[$clave] = ['id' => $clave, 'unidad_id' => $base['unidad_id'], 'unidad' => $base['unidad'], 'regimen_id' => $base['regimen_id'], 'ley' => $base['ley'], 'tipo_personal' => $base['tipo_personal'],
                        'estado_codigo' => strtolower($base['tipo_personal']), 'estado' => $base['tipo_personal'], 'personas_total' => 0, '_personas' => []];
                }
                $grupos[$clave]['personas_total']++;
                $grupos[$clave]['_personas'][] = $persona->id;

                continue;
            }
            if (in_array($tipo, ['contratos', 'movimientos'], true)) {
                foreach ($persona->vinculosLaborales as $vinculo) {
                    $datos = self::contextoVinculo($base, $vinculo);
                    if (! self::unidadCoincide($datos, $filtros)) {
                        continue;
                    }
                    if ($tipo === 'contratos') {
                        $fin = $vinculo->vinculoAdendas->first()?->fecha_fin ?? $vinculo->fecha_fin_prevista;
                        $hoy = today('America/Lima');
                        $estado = match (true) {
                            $vinculo->fecha_cese !== null => 'cesado',
                            $vinculo->fecha_inicio > $hoy => 'programado',
                            $fin !== null && $fin < $hoy => 'plazo_vencido',
                            default => 'abierto',
                        };
                        yield [...$datos, 'id' => $vinculo->id, 'fecha' => $vinculo->fecha_inicio?->toDateString(), 'fecha_fin' => $fin?->toDateString(),
                            'fecha_cese' => $vinculo->fecha_cese?->toDateString(), 'numero' => $vinculo->numero_documento_vinculo, 'adendas' => $vinculo->vinculoAdendas->count(),
                            'estado_codigo' => $estado, 'estado' => self::catalogo()[$tipo]['estados'][$estado]];
                    } else {
                        yield [...$datos, 'id' => $vinculo->id.':ingreso', 'fecha' => $vinculo->fecha_inicio?->toDateString(),
                            'estado_codigo' => 'ingreso', 'estado' => 'Ingreso', 'numero' => $vinculo->numero_documento_vinculo, 'motivo' => 'Inicio del vínculo'];
                        $cese = $vinculo->desvinculaciones->first();
                        $fecha = $vinculo->fecha_cese ?? $cese?->fecha_cese;
                        if ($fecha) {
                            yield [...$datos, 'id' => $vinculo->id.':cese', 'fecha' => $fecha->toDateString(), 'estado_codigo' => 'cese', 'estado' => 'Cese',
                                'numero' => $cese?->numero_resolucion, 'motivo' => $cese?->motivo_cese ? self::etiqueta($cese->motivo_cese) : 'Cese registrado en el vínculo'];
                        }
                    }
                }
                if ($tipo === 'movimientos' && self::unidadCoincide($base, $filtros)) {
                    foreach ($persona->desvinculacionesPorUsuario as $cese) {
                        yield [...$base, 'id' => $cese->id, 'fecha' => $cese->fecha_cese?->toDateString(), 'estado_codigo' => 'cese', 'estado' => 'Cese',
                            'numero' => $cese->numero_resolucion, 'motivo' => self::etiqueta($cese->motivo_cese), 'origen_puesto' => 'Ficha actual: cese sin vínculo asociado'];
                    }
                }

                continue;
            }
            foreach ($persona->{$relacion} as $registro) {
                if (($tipo === 'formacion' && ! empty($filtros['nivel']) && $registro->nivel !== $filtros['nivel'])
                    || ($tipo === 'capacitaciones' && ! empty($filtros['modalidad']) && $registro->tipo !== $filtros['modalidad'])) {
                    continue;
                }
                $datos = $base;
                if ($tipo === 'vacaciones') {
                    if ($registro->vinculoLaboral?->anulado) {
                        continue;
                    }
                    if ($registro->vinculoLaboral) {
                        $datos = self::contextoVinculo($base, $registro->vinculoLaboral);
                    }
                }
                if (! self::unidadCoincide($datos, $filtros)) {
                    continue;
                }
                if (in_array($tipo, ['vacaciones', 'evaluaciones'], true) && ! empty($filtros['anio'])
                    && (int) ($tipo === 'vacaciones' ? $registro->periodo_anio : $registro->ciclo_anio) !== (int) $filtros['anio']) {
                    continue;
                }
                $doc = $registro->documento;
                if ($tipo === 'licencias' && ! $actor->veDatosSensibles()
                    && (in_array($registro->tipo, Seccion05::DATOS_SALUD, true) || $doc?->tipoDocumento?->es_dato_salud || $doc?->tipoDocumento?->es_dato_sensible)) {
                    continue;
                }
                $estadoArchivo = $doc?->archivo_id && ! in_array($doc->estado, ['anulado', 'falso'], true) ? 'con_archivo' : 'sin_archivo';
                $comun = [...$datos, 'id' => $registro->id, 'sustento' => $doc ? self::etiqueta($doc->estado) : 'Sin documento asociado'];
                if ($tipo === 'formacion') {
                    yield [...$comun, 'fecha' => $registro->fecha_obtencion?->toDateString(), 'nivel' => self::etiqueta($registro->nivel),
                        'detalle' => $registro->denominacion, 'entidad' => $registro->institucion, 'registro' => $registro->numero_registro_sunedu,
                        'estado_codigo' => $estadoArchivo, 'estado' => self::catalogo()[$tipo]['estados'][$estadoArchivo]];
                } elseif ($tipo === 'capacitaciones') {
                    yield [...$comun, 'fecha' => $registro->fecha_fin?->toDateString(), 'inicio' => $registro->fecha_inicio?->toDateString(),
                        'detalle' => $registro->nombre, 'entidad' => $registro->entidad, 'modalidad' => self::etiqueta($registro->tipo),
                        'horas' => $registro->horas !== null && $registro->horas >= 0 ? $registro->horas : null,
                        'estado_codigo' => $estadoArchivo, 'estado' => self::catalogo()[$tipo]['estados'][$estadoArchivo]];
                } elseif ($tipo === 'licencias') {
                    $estado = $registro->con_goce === null ? 'sin_definir' : ($registro->con_goce ? 'con_goce' : 'sin_goce');
                    yield [...$comun, 'fecha' => $registro->fecha_inicio?->toDateString(), 'fin' => $registro->fecha_fin?->toDateString(), 'dias' => $registro->dias,
                        'detalle' => self::etiqueta($registro->tipo), 'numero' => $registro->numero_documento, 'estado_codigo' => $estado, 'estado' => self::catalogo()[$tipo]['estados'][$estado]];
                } elseif ($tipo === 'vacaciones') {
                    $goces = $registro->gocesVacacionales->where('anulado', false)->where('usuario_id', $persona->id);
                    $goce = (int) $goces->where('tipo', 'goce')->sum('dias');
                    $adelanto = (int) $goces->where('tipo', 'adelanto')->sum('dias');
                    $estado = $goces->whereIn('tipo', ['goce', 'adelanto'])->isNotEmpty() ? 'con_movimientos' : 'sin_movimientos';
                    yield [...$comun, 'periodo' => $registro->periodo_anio, 'derecho' => $registro->dias_derecho, 'goce' => $goce, 'adelanto' => $adelanto,
                        'postergacion' => (int) $goces->where('tipo', 'postergacion')->sum('dias'), 'estado_codigo' => $estado, 'estado' => self::catalogo()[$tipo]['estados'][$estado]];
                } else {
                    $estado = $registro->plan_mejora ? 'con_plan' : 'sin_plan';
                    yield [...$comun, 'fecha' => $registro->fecha_evaluacion?->toDateString(), 'periodo' => $registro->ciclo_anio,
                        'estado_codigo' => $estado, 'estado' => self::catalogo()[$tipo]['estados'][$estado]];
                }
            }
        }
        foreach ($grupos as $grupo) {
            yield $grupo;
        }
    }

    private static function contextoVinculo(array $base, VinculoLaboral $vinculo): array
    {
        return [...$base, 'unidad_id' => $vinculo->unidad_organica_id, 'unidad' => $vinculo->unidadOrganica?->nombre ?? 'Sin unidad',
            'tipo_personal' => ucfirst($vinculo->tipo_personal), 'puesto' => $vinculo->nombre_puesto,
            'regimen_id' => $vinculo->regimen_normativo_id, 'ley' => $vinculo->regimenNormativo?->codigo ?? 'Sin definir', 'tipo_vinculo' => self::etiqueta($vinculo->regimen_laboral), 'origen_puesto' => 'Vínculo del registro'];
    }

    private static function unidadCoincide(array $base, array $filtros): bool
    {
        return (empty($filtros['unidad_id']) || $base['unidad_id'] === $filtros['unidad_id'])
            && (empty($filtros['tipo_personal']) || strtolower($base['tipo_personal']) === $filtros['tipo_personal']);
    }

    public static function etiqueta(?string $valor): string
    {
        return ['maestria' => 'Maestría', 'educacion_basica' => 'Educación básica', 'titulo_profesional' => 'Título profesional',
            'tecnico' => 'Técnico', 'programa_especializacion' => 'Programa de especialización'][$valor ?? ''] ?? ($valor ? ucfirst(str_replace('_', ' ', $valor)) : 'Sin definir');
    }

    private static function tiposVisibles(Usuario $actor, array $filtros): Collection
    {
        $consulta = TipoDocumento::query()->where('activo', true)->whereBetween('seccion_legajo_id', [1, 11])->orderBy('seccion_legajo_id')->orderBy('orden')->orderBy('id');
        if (! empty($filtros['seccion'])) {
            $consulta->where('seccion_legajo_id', (int) $filtros['seccion']);
        }

        return $consulta->get()->filter(fn (TipoDocumento $t): bool => self::tipoVisible($actor, $t) && ConfiguracionFuncionalidades::tipoActivo($t->codigo, $t->seccion_legajo_id));
    }

    private static function tipoVisible(Usuario $actor, TipoDocumento $tipo): bool
    {
        return self::seccionVisible($actor, $tipo->seccion_legajo_id)
            && ($actor->veDatosSensibles() || (! $tipo->es_dato_sensible && ! $tipo->es_dato_salud))
            && ($actor->can('seccion08.sanciones.ver') || ! in_array($tipo->codigo, ['SAN_RESOLUCION_SANCION', 'SAN_RESOLUCION_RECURSO'], true));
    }

    private static function seccionVisible(Usuario $actor, int $seccion): bool
    {
        return (! in_array($seccion, [1, 9, 10], true) || $actor->veDatosSensibles()) && ($seccion !== 7 || $actor->can('seccion07.ver'));
    }

    private static function columnas(string $tipo): array
    {
        if (isset(ReportesSecciones::DEFINICIONES[$tipo])) {
            return ReportesSecciones::columnas($tipo);
        }
        if ($tipo === 'dotacion') {
            return [['key' => 'unidad', 'label' => 'Unidad orgánica', 'type' => 'text'], ['key' => 'ley', 'label' => 'Ley / régimen', 'type' => 'text'],
                ['key' => 'tipo_personal', 'label' => 'Tipo de personal', 'type' => 'text'], ['key' => 'personas_total', 'label' => 'Personas', 'type' => 'number']];
        }
        $comunes = ['nombre' => 'Persona', 'documento' => 'DNI / documento', 'tipo_personal' => 'Tipo de personal', 'unidad' => 'Unidad orgánica'];
        $otras = match ($tipo) {
            'contratos' => ['puesto' => 'Puesto', 'ley' => 'Ley / régimen', 'tipo_vinculo' => 'Tipo de vínculo', 'numero' => 'Documento del vínculo', 'fecha' => 'Inicio', 'fecha_fin' => 'Fin con adendas', 'adendas' => 'Adendas', 'fecha_cese' => 'Cese', 'estado' => 'Estado'],
            'movimientos' => ['puesto' => 'Puesto', 'ley' => 'Ley / régimen', 'estado' => 'Movimiento', 'fecha' => 'Fecha', 'numero' => 'Documento / resolución', 'motivo' => 'Motivo', 'origen_puesto' => 'Fuente de la unidad'],
            'formacion' => ['nivel' => 'Nivel', 'detalle' => 'Título / grado', 'entidad' => 'Institución formadora', 'fecha' => 'Obtención', 'registro' => 'Registro SUNEDU declarado', 'estado' => 'Archivo', 'sustento' => 'Estado del sustento'],
            'capacitaciones' => ['modalidad' => 'Tipo de capacitación', 'detalle' => 'Capacitación', 'entidad' => 'Entidad', 'inicio' => 'Inicio', 'fecha' => 'Finalización', 'horas' => 'Horas declaradas', 'estado' => 'Archivo', 'sustento' => 'Estado del sustento'],
            'vacaciones' => ['puesto' => 'Puesto del vínculo', 'periodo' => 'Período', 'derecho' => 'Días de derecho registrados', 'goce' => 'Días de goce registrados', 'adelanto' => 'Días adelantados', 'postergacion' => 'Días postergados', 'estado' => 'Movimientos'],
            'licencias' => ['detalle' => 'Tipo de licencia / permiso', 'fecha' => 'Inicio', 'fin' => 'Fin', 'dias' => 'Días registrados completos', 'estado' => 'Goce', 'numero' => 'Documento de autorización'],
            'evaluaciones' => ['periodo' => 'Ciclo', 'fecha' => 'Evaluación', 'estado' => 'Plan de mejora', 'sustento' => 'Estado del sustento'],
            'personal' => ['puesto' => 'Puesto', 'origen_puesto' => 'Fuente del puesto', 'ley' => 'Ley / régimen', 'tipo_vinculo' => 'Tipo de vínculo', 'vinculos' => 'Vínculos abiertos', 'estado' => 'Situación laboral'],
            'inventario' => ['legajo' => 'Legajo', 'estado' => 'Estado', 'formato' => 'Formato', 'ubicacion' => 'Ubicación', 'documentos' => 'Documentos visibles', 'folios' => 'Folios visibles', 'por_seccion' => 'Folios por sección', 'fecha' => 'Apertura'],
            'pendientes' => ['legajo' => 'Legajo', 'seccion' => 'Sección', 'detalle' => 'Documento / requisito', 'estado' => 'Pendiente', 'motivo' => 'Motivo'],
            default => ['origen' => 'Origen', 'detalle' => 'Concepto', 'fecha' => 'Vencimiento', 'dias' => 'Días restantes', 'estado' => 'Estado'],
        };

        return collect([...$comunes, ...$otras])->map(fn (string $etiqueta, string $clave): array => ['key' => $clave, 'label' => $etiqueta,
            'type' => in_array($clave, ['fecha', 'fecha_fin', 'fecha_cese', 'inicio', 'fin'], true) ? 'date' : (in_array($clave, ['vinculos', 'documentos', 'folios', 'dias', 'horas', 'adendas', 'periodo', 'derecho', 'goce', 'adelanto', 'postergacion'], true) ? 'number' : 'text')])->values()->all();
    }
}
