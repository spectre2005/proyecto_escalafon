<?php

namespace App\Support;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\Documentos\EstadoDocumento;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Datos del informe escalafonario y de las constancias de trabajo y de tiempo de servicios de una persona (el PDF se arma
 * en el frontend). Campos explícitos, nunca modelos completos ni identificadores internos. Solo lee: no cambia registros.
 *
 * - Informe escalafonario: lo registrado en el legajo, con el sustento de cada dato; no es una certificación.
 * - Constancia de trabajo: solo vínculos no anulados sustentados con un documento aceptado de la sección 02.
 * - Constancia de tiempo de servicios: solo las resoluciones de reconocimiento de la sección 06 con documento aceptado.
 *   No suma resoluciones (pueden solaparse) ni calcula tiempo con vínculos, licencias, suspensiones o estudios: esas
 *   reglas no están definidas para cada régimen.
 * La visibilidad es la de los listados del legajo: sanciones con seccion08.sanciones.ver, evaluaciones con seccion07.ver
 * (sin calificación), y nunca descansos médicos ni documentos sensibles o de salud.
 */
final class EmisionesPersonales
{
    public const DOCUMENTOS = [
        'informe-escalafonario' => 'Informe escalafonario',
        'constancia-trabajo' => 'Constancia de trabajo',
        'constancia-tiempo-servicios' => 'Constancia de tiempo de servicios',
    ];

    /** Etiquetas visibles de los tipos que valida la sección 08 (las de sanciones, como en su pantalla); el valor guardado no cambia. */
    private const TIPOS_RECONOCIMIENTO = ['carta' => 'Felicitación', 'labor_docente' => 'Reconocimiento por labor docente', 'tiempo_servicios' => 'Reconocimiento por tiempo de servicios'];

    private const TIPOS_SANCION = ['AMONESTACION_ESCRITA' => 'Amonestación escrita', 'SUSPENSION' => 'Suspensión', 'DESTITUCION' => 'Destitución'];

    private const TIPOS_DOC_IDENTIDAD = ['dni' => 'DNI', 'ce' => 'Carné de extranjería', 'pasaporte' => 'Pasaporte', 'otro' => 'Otro'];

    public static function emitir(Usuario $actor, Usuario $persona, Legajo $legajo, string $documento): array
    {
        $comun = ['documento' => $documento, 'titulo' => self::DOCUMENTOS[$documento], 'emitido_en' => now('America/Lima')->format('d/m/Y'),
            'persona' => self::persona($persona, $legajo)];

        return match ($documento) {
            'informe-escalafonario' => [...$comun, ...self::informe($actor, $persona)],
            'constancia-trabajo' => [...$comun, ...self::constanciaTrabajo($persona)],
            default => [...$comun, ...self::constanciaTiempo($actor, $persona)],
        };
    }

    private static function persona(Usuario $persona, Legajo $legajo): array
    {
        return ['nombre' => trim($persona->apellido_paterno.' '.$persona->apellido_materno).', '.$persona->nombres,
            'tipo_documento' => self::TIPOS_DOC_IDENTIDAD[$persona->tipo_doc_identidad] ?? ($persona->tipo_doc_identidad ?: null),
            'numero_documento' => $persona->numero_doc_identidad ?: null,
            'tipo_personal' => $persona->tipo_personal ? ConsultaReportes::etiqueta($persona->tipo_personal) : null,
            'numero_legajo' => $legajo->numero_legajo ?: null, 'estado_legajo' => $legajo->estado_legajo === 'activo' ? 'Activo' : 'Pasivo',
            'fecha_apertura' => self::fecha($legajo->fecha_apertura)];
    }

    private static function informe(Usuario $actor, Usuario $persona): array
    {
        $sanciones = $actor->can('seccion08.sanciones.ver');
        $evaluaciones = $actor->can('seccion07.ver');
        $conDocumento = fn (Builder|Relation $q): Builder|Relation => $q->where('anulado', false)->with(['documento.tipoDocumento', 'documento.archivo', 'documento.legajo:id,usuario_id', 'documento.documentosUsuarioPorSubsanaADocumento:id,subsana_a_documento_id']);
        $persona->load(array_filter([
            'formacionAcademicaPorUsuario' => fn ($q) => $conDocumento($q)->orderBy('fecha_obtencion')->orderBy('id'),
            'capacitacionesPorUsuario' => fn ($q) => $conDocumento($q)->orderBy('fecha_inicio')->orderBy('id'),
            'reconocimientosPorUsuario' => fn ($q) => $conDocumento($q)->orderBy('fecha')->orderBy('id'),
            'licenciasPermisosPorUsuario' => fn ($q) => $conDocumento($q)->whereNotIn('tipo', Seccion05::DATOS_SALUD)->orderBy('fecha_inicio')->orderBy('id'),
            'sancionesPorUsuario' => $sanciones ? fn ($q) => $conDocumento($q)->orderBy('fecha_resolucion')->orderBy('id') : null,
            'evaluacionesDesempenoPorUsuario' => $evaluaciones ? fn ($q) => $conDocumento($q)->orderBy('ciclo_anio')->orderBy('id') : null,
        ]));
        $filas = fn (Collection $registros, \Closure $fila): array => $registros->filter(fn ($r): bool => self::visible($actor, $r->documento, $persona))
            ->map(fn ($r): array => [...$fila($r), 'sustento' => self::sustento($r->documento, $persona)['texto']])->values()->all();

        return [
            'vinculos' => self::vinculos($persona)->map(fn (array $v): array => collect($v)->except('acreditado')->all())->values()->all(),
            'tiempos_reconocidos' => self::tiempos($actor, $persona)->map(fn (array $t): array => collect($t)->except('certificable')->all())->values()->all(),
            'formacion' => $filas($persona->formacionAcademicaPorUsuario, fn ($r): array => ['nivel' => ConsultaReportes::etiqueta($r->nivel), 'denominacion' => $r->denominacion,
                'institucion' => $r->institucion, 'fecha_obtencion' => self::fecha($r->fecha_obtencion), 'registro_sunedu' => $r->numero_registro_sunedu ?: null]),
            'capacitaciones' => $filas($persona->capacitacionesPorUsuario, fn ($r): array => ['nombre' => $r->nombre, 'entidad' => $r->entidad ?: null,
                'modalidad' => ConsultaReportes::etiqueta($r->tipo), 'horas' => $r->horas !== null && $r->horas >= 0 ? $r->horas : null,
                'inicio' => self::fecha($r->fecha_inicio), 'fin' => self::fecha($r->fecha_fin)]),
            'reconocimientos' => $filas($persona->reconocimientosPorUsuario, fn ($r): array => ['tipo' => self::TIPOS_RECONOCIMIENTO[$r->tipo] ?? ConsultaReportes::etiqueta($r->tipo), 'motivo' => $r->motivo,
                'otorgado_por' => $r->otorgado_por ?: null, 'numero_documento' => $r->numero_documento ?: null, 'fecha' => self::fecha($r->fecha)]),
            'sanciones' => $sanciones ? $filas($persona->sancionesPorUsuario, fn ($r): array => ['tipo' => self::TIPOS_SANCION[$r->tipo] ?? ConsultaReportes::etiqueta($r->tipo),
                'numero_resolucion' => $r->numero_resolucion, 'fecha_resolucion' => self::fecha($r->fecha_resolucion), 'inicio' => self::fecha($r->fecha_inicio),
                'fin' => self::fecha($r->fecha_fin), 'dias_suspension' => $r->dias_suspension]) : null,
            'licencias' => $filas($persona->licenciasPermisosPorUsuario, fn ($r): array => ['tipo' => ConsultaReportes::etiqueta($r->tipo),
                'goce' => $r->con_goce === null ? null : ($r->con_goce ? 'Con goce' : 'Sin goce'), 'inicio' => self::fecha($r->fecha_inicio),
                'fin' => self::fecha($r->fecha_fin), 'dias' => $r->dias, 'numero_documento' => $r->numero_documento ?: null]),
            'evaluaciones' => $evaluaciones ? $filas($persona->evaluacionesDesempenoPorUsuario, fn ($r): array => ['ciclo' => $r->ciclo_anio,
                'fecha' => self::fecha($r->fecha_evaluacion), 'plan_mejora' => $r->plan_mejora ? 'Sí' : 'No']) : null,
            'omitidas' => array_values(array_filter([$sanciones ? null : 'Sanciones disciplinarias', $evaluaciones ? null : 'Evaluaciones de desempeño'])),
        ];
    }

    private static function constanciaTrabajo(Usuario $persona): array
    {
        [$acreditados, $otros] = self::vinculos($persona)->partition(fn (array $v): bool => $v['acreditado']);
        $vigente = $acreditados->contains(fn (array $v): bool => $v['situacion_codigo'] === 'vigente');

        return ['emitible' => $acreditados->isNotEmpty(),
            'motivo' => $acreditados->isEmpty() ? 'Ningún vínculo laboral tiene un documento de vinculación aceptado en la sección 02 del legajo.' : null,
            'vinculos' => $acreditados->map(fn (array $v): array => collect($v)->except(['acreditado', 'sustento'])->all())->values()->all(),
            'situacion' => $vigente ? 'Mantiene un vínculo laboral vigente a la fecha de emisión.'
                : 'No se registra un vínculo laboral sustentado que esté vigente a la fecha de emisión.',
            'no_incluidos' => $otros->map(fn (array $v): array => ['puesto' => $v['puesto'], 'inicio' => $v['inicio'], 'motivo' => $v['sustento']])->values()->all()];
    }

    private static function constanciaTiempo(Usuario $actor, Usuario $persona): array
    {
        [$certificables, $otros] = self::tiempos($actor, $persona)->partition(fn (array $t): bool => $t['certificable']);

        return ['emitible' => $certificables->isNotEmpty(),
            'motivo' => $certificables->isEmpty() ? 'No hay tiempo de servicios reconocido por resolución con documento aceptado en la sección 06 del legajo.' : null,
            'tiempos' => $certificables->map(fn (array $t): array => collect($t)->except(['certificable', 'sustento'])->all())->values()->all(),
            'no_incluidos' => $otros->map(fn (array $t): array => ['numero_resolucion' => $t['numero_resolucion'], 'motivo' => $t['sustento']])->values()->all()];
    }

    /** Vínculos no anulados, del más antiguo al más reciente, con su situación y su documento de vinculación (sección 02). */
    private static function vinculos(Usuario $persona): Collection
    {
        $vinculos = $persona->vinculosLaborales()->where('anulado', false)->with(['unidadOrganica:id,nombre', 'regimenNormativo:id,nombre', 'vinculoAdendas'])
            ->orderBy('fecha_inicio')->orderBy('id')->get();
        // Los documentos de la sección 02 de la persona, una sola vez: los enlazados al vínculo y los que los subsanan.
        $documentos = $vinculos->isEmpty() ? collect() : DocumentoUsuario::query()->where('usuario_id', $persona->id)->where('seccion_legajo_id', 2)
            ->with(['tipoDocumento', 'archivo', 'legajo:id,usuario_id'])->get();
        $subsanadoPor = $documentos->whereNotNull('subsana_a_documento_id')->groupBy('subsana_a_documento_id');

        return $vinculos->map(function (VinculoLaboral $v) use ($documentos, $subsanadoPor, $persona): array {
            $vigentes = $documentos->where('vinculo_laboral_id', $v->id)->map(fn (DocumentoUsuario $d): DocumentoUsuario => self::ultimaSubsanacion($d, $subsanadoPor))
                ->map(fn (DocumentoUsuario $d): array => self::sustento($d, $persona, false));
            $acreditado = $vigentes->contains(fn (array $s): bool => $s['aceptado']);
            $fin = VigenciaLaboral::fin($v);

            return ['puesto' => $v->nombre_puesto, 'unidad' => $v->unidadOrganica?->nombre, 'tipo_personal' => ConsultaReportes::etiqueta($v->tipo_personal),
                'ley' => $v->regimenNormativo?->nombre, 'tipo_vinculo' => RegimenesLaborales::TIPOS_VINCULO[$v->regimen_laboral] ?? null,
                // N.º oficial (resolución o contrato) tal como se registró; el identificador interno del vínculo no sale.
                'numero_oficial' => filled($v->numero_documento_vinculo) ? $v->numero_documento_vinculo : null,
                'inicio' => self::fecha($v->fecha_inicio), 'fin_previsto' => self::fecha($fin), 'cese' => self::fecha($v->fecha_cese),
                ...self::situacion($v), 'acreditado' => $acreditado,
                'sustento' => $acreditado ? 'Documento de vinculación aceptado' : ($vigentes->first()['texto'] ?? 'Sin documento de vinculación en el legajo')];
        });
    }

    /** Situación compartida con la ficha (VigenciaLaboral::situacion), con las claves que usa el PDF. */
    private static function situacion(VinculoLaboral $v): array
    {
        $situacion = VigenciaLaboral::situacion($v);

        return ['situacion_codigo' => $situacion['codigo'], 'situacion' => $situacion['texto']];
    }

    /** Resoluciones de reconocimiento de tiempo de servicios (sección 06), tal como se registraron, sin sumarlas. */
    private static function tiempos(Usuario $actor, Usuario $persona): Collection
    {
        return $persona->tiempoServiciosReconocidosPorUsuario()->where('anulado', false)
            ->with(['documento.tipoDocumento', 'documento.archivo', 'documento.legajo:id,usuario_id', 'documento.documentosUsuarioPorSubsanaADocumento:id,subsana_a_documento_id'])
            ->orderBy('fecha_resolucion')->orderBy('id')->get()
            ->filter(fn ($t): bool => self::visible($actor, $t->documento, $persona))
            ->map(function ($t) use ($persona): array {
                $sustento = self::sustento($t->documento, $persona);
                $valido = $t->anios !== null && $t->meses !== null && $t->dias !== null && min($t->anios, $t->meses, $t->dias) >= 0;

                return ['numero_resolucion' => $t->numero_resolucion ?: null, 'fecha_resolucion' => self::fecha($t->fecha_resolucion),
                    'anios' => $t->anios, 'meses' => $t->meses, 'dias' => $t->dias,
                    'certificable' => $sustento['aceptado'] && $valido && filled($t->numero_resolucion),
                    'sustento' => ! $valido ? 'Años, meses o días incompletos o inválidos' : (filled($t->numero_resolucion) ? $sustento['texto'] : 'Sin número de resolución')];
            })->values();
    }

    private static function ultimaSubsanacion(DocumentoUsuario $documento, Collection $subsanadoPor): DocumentoUsuario
    {
        $vistos = [];
        while (($siguiente = $subsanadoPor->get($documento->id)?->sortByDesc('created_at')->first()) && ! isset($vistos[$siguiente->id])) {
            $vistos[$siguiente->id] = true;
            $documento = $siguiente;
        }

        return $documento;
    }

    /** Sustento de un dato: aceptado solo si el documento es de la persona, sigue vigente y cumple EstadoDocumento::aprobado. */
    private static function sustento(?DocumentoUsuario $documento, Usuario $persona, bool $reemplazoCuenta = true): array
    {
        $texto = match (true) {
            $documento === null => 'Sin documento de sustento',
            $documento->usuario_id !== $persona->id || $documento->legajo?->usuario_id !== $persona->id => 'Documento de otra persona',
            in_array($documento->estado, ['anulado', 'falso'], true) => 'Documento anulado',
            $reemplazoCuenta && $documento->documentosUsuarioPorSubsanaADocumento->isNotEmpty() => 'Documento reemplazado por una subsanación',
            ! EstadoDocumento::aprobado($documento, $documento->tipoDocumento) => 'Documento no aceptado ('.ConsultaReportes::etiqueta(EstadoDocumento::visible($documento)).')',
            default => null,
        };

        return ['aceptado' => $texto === null, 'texto' => $texto ?? 'Documento aceptado'];
    }

    /** Mismo criterio que los informes del legajo: sin datos sensibles ni de salud para quien no los ve. */
    private static function visible(Usuario $actor, ?DocumentoUsuario $documento, Usuario $persona): bool
    {
        $tipo = $documento?->usuario_id === $persona->id ? $documento->tipoDocumento : null;

        return ! $tipo || (! $tipo->es_dato_salud && ($actor->veDatosSensibles() || ! $tipo->es_dato_sensible)
            && ($actor->can('seccion08.sanciones.ver') || ! in_array($tipo->codigo, ['SAN_RESOLUCION_SANCION', 'SAN_RESOLUCION_RECURSO'], true)));
    }

    private static function fecha(?CarbonInterface $fecha): ?string
    {
        return $fecha?->format('d/m/Y');
    }
}
