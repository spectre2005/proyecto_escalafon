<?php

namespace App\Support\Documentos;

use App\Http\Controllers\Api\V1\Legajos\DocumentoLegajoController;
use App\Models\DeclaracionVerificacion;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\ConfiguracionFuncionalidades;
use App\Support\Seccion01;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Vista previa del expediente imprimible (fase 1, secciones 01 a 04): qué documentos del legajo irían al índice y al
 * futuro ZIP, por sección y por folio (Directiva, num. 9.3). Solo lee: no toca archivos, folios ni estados.
 *
 * - Alcance: los tipos de las funcionalidades de FUNCIONALIDADES; se activan y desactivan con
 *   ConfiguracionFuncionalidades::tipoActivo, como en el resto del legajo.
 * - Acceso: la regla del visor (DocumentoLegajoController::restriccion). Lo que el usuario no puede ver no se describe.
 * - Solo documentos del legajo con archivo; las entregas de Requerimientos, los datos de pantalla, la foto y los
 *   documentos generados no forman parte del legajo.
 * - Subsanados y anulados se muestran con su estado y sus folios (nada se retira del legajo físico, num. 5.3).
 */
final class ExpedienteImprimible
{
    /** Secciones que siempre se incluyen. */
    public const SECCIONES = [1, 2, 3, 4];

    /** Funcionalidades que siempre se incluyen (secciones 01 a 04). */
    public const FUNCIONALIDADES = ['s01.identidad', 's01.declaraciones', 's02.seleccion', 's02.vinculos', 's02.inducciones', 's03.formacion', 's04.experiencia'];

    /**
     * Grupos que el usuario puede añadir a la copia; ninguno se incluye si no se pide.
     * clave => [sección, funcionalidades | null = toda la sección, incluidos sus «Otros documentos», nombre].
     */
    public const OPCIONALES = [
        'familiares' => [1, ['s01.familiares'], 'Familiares (DNI de cónyuge e hijos, partida de matrimonio)'],
        'discapacidad' => [1, ['s01.discapacidad'], 'Certificado de discapacidad'],
        'periodo_prueba' => [2, ['s02.periodos_prueba'], 'Período de prueba'],
        'seccion_05' => [5, null, 'Sección 05'], 'seccion_06' => [6, null, 'Sección 06'], 'seccion_07' => [7, null, 'Sección 07'],
        'seccion_08' => [8, null, 'Sección 08'], 'seccion_09' => [9, null, 'Sección 09'], 'seccion_10' => [10, null, 'Sección 10'],
        'seccion_11' => [11, null, 'Sección 11'],
    ];

    /**
     * @param  list<string>  $incluir  claves de OPCIONALES que se añaden a la copia
     * @return array<string, mixed>
     */
    public static function vistaPrevia(Usuario $actor, Usuario $persona, Legajo $legajo, string $orden = 'asc', array $incluir = []): array
    {
        $disco = (string) config('legajo.disco_documentos');
        $incluir = array_values(array_intersect(array_keys(self::OPCIONALES), $incluir));
        $funcionalidades = self::FUNCIONALIDADES;
        $seccionesCompletas = [];
        foreach ($incluir as $clave) {
            [$numero, $propias] = self::OPCIONALES[$clave];
            $propias === null ? $seccionesCompletas[] = $numero : array_push($funcionalidades, ...$propias);
        }
        $seccionesVista = array_values(array_unique([...self::SECCIONES, ...$seccionesCompletas]));
        sort($seccionesVista);
        $nombres = SeccionLegajo::query()->whereIn('id', $seccionesVista)->pluck('nombre', 'id');
        $tipos = TipoDocumento::query()->with('subseccionLegajo:id,codigo,nombre')->where('activo', true)
            ->whereIn('seccion_legajo_id', $seccionesVista)->orderBy('seccion_legajo_id')->orderBy('orden')->get()
            ->filter(fn (TipoDocumento $t): bool => in_array($t->seccion_legajo_id, $seccionesCompletas, true)
                || array_intersect(ConfiguracionFuncionalidades::deTipo($t->codigo, $t->seccion_legajo_id), $funcionalidades) !== []);
        $advertencias = [];
        // Dos cosas distintas: acceso (¿el usuario ve todas las secciones de la vista?) e integridad (¿se puede reproducir
        // cada documento registrado de lo que ve?). La integridad nunca se calcula sobre lo restringido.
        $accesoParcial = false;
        $incidencias = 0;
        $secciones = [];
        foreach ($seccionesVista as $seccion) {
            $nombre = (string) ($nombres[$seccion] ?? 'Sección '.$seccion);
            // Toda la sección restringida (01 sin datos sensibles): no se describe nada de ella.
            if (DocumentoLegajoController::restriccion($actor, $seccion) !== null) {
                $accesoParcial = true;
                $secciones[] = ['id' => $seccion, 'nombre' => $nombre, 'estado' => 'omitida_permisos'];
                $advertencias[] = ['tipo' => 'omitida_permisos', 'seccion' => $seccion, 'mensaje' => "Sección {$seccion}: omitida por permisos. Esta vista no incluye todas las secciones."];

                continue;
            }
            $propios = $tipos->where('seccion_legajo_id', $seccion);
            $visibles = $propios->filter(fn (TipoDocumento $t): bool => DocumentoLegajoController::restriccion($actor, $seccion, $t) === null);
            if ($visibles->count() < $propios->count()) {
                $accesoParcial = true;
                $advertencias[] = ['tipo' => 'omitida_permisos', 'seccion' => $seccion, 'mensaje' => "Sección {$seccion}: se omitieron documentos por permisos."];
            }
            $activos = $visibles->filter(fn (TipoDocumento $t): bool => ConfiguracionFuncionalidades::tipoActivo($t->codigo, $seccion));
            $registros = DocumentoUsuario::query()->with('archivo')->withCount('documentosUsuarioPorSubsanaADocumento as subsanado_por')
                ->where('legajo_id', $legajo->id)->where('usuario_id', $persona->id)->whereIn('tipo_documento_id', $activos->pluck('id'))->get()
                // Por folio, en PHP: SQLite y PostgreSQL no ubican igual los nulos.
                ->sortBy(fn (DocumentoUsuario $d): array => [$d->folio_inicio === null ? 1 : 0, $d->folio_inicio ?? 0, $d->id])->values();
            $sinArchivo = $registros->whereNull('archivo_id')->count();
            if ($sinArchivo) {
                $advertencias[] = ['tipo' => 'sin_archivo', 'seccion' => $seccion, 'mensaje' => "Sección {$seccion}: {$sinArchivo} registro(s) sin archivo; no se imprimen."];
            }
            // foreach y no map(fn): documento() añade advertencias por referencia.
            $documentos = collect();
            foreach ($registros->whereNotNull('archivo_id') as $d) {
                $documentos->push(self::documento($d, $tipos->firstWhere('id', $d->tipo_documento_id), $seccion, $nombre, $disco, $advertencias));
            }
            if ($orden === 'desc') {
                $documentos = $documentos->reverse()->values();
            }
            $sinFoliar = $seccion === 1 ? self::sinFoliar($persona, $activos, $nombre, $advertencias) : [];
            $folios = (int) $documentos->sum('folios');
            $ultimo = Folios::ultimo($legajo, $seccion);
            // Folios de la sección que esta vista no cubre (tipos desactivados o fuera del alcance de la fase 1): se avisa,
            // pero no es un problema de acceso ni de integridad de lo mostrado.
            if ($ultimo > $folios) {
                $advertencias[] = ['tipo' => 'folios_no_incluidos', 'seccion' => $seccion, 'mensaje' => "Sección {$seccion}: el legajo registra {$ultimo} folio(s) y esta vista incluye {$folios}."];
            }
            // Documentos registrados que no se pueden reproducir: archivo ausente, infectado o ilegible, o sin archivo.
            $incidenciasSeccion = $sinArchivo + collect([...$documentos->all(), ...$sinFoliar])->where('inclusion', 'no_disponible')->count();
            $incidencias += $incidenciasSeccion;
            $secciones[] = [
                'id' => $seccion, 'nombre' => $nombre, 'incidencias' => $incidenciasSeccion,
                'estado' => $activos->isEmpty() ? 'desactivada' : ($documentos->isEmpty() && $sinFoliar === [] ? 'sin_documentos' : 'disponible'),
                'ultimo_folio' => $ultimo, 'folios' => $folios,
                'tipos' => $visibles->map(fn (TipoDocumento $t): array => [
                    'codigo' => $t->codigo, 'nombre' => $t->nombre, 'subseccion' => $t->subseccionLegajo?->only(['codigo', 'nombre']),
                    'estado' => ! $activos->contains('id', $t->id) ? 'desactivada' : ($documentos->contains('tipo.codigo', $t->codigo) ? 'disponible' : 'sin_documentos'),
                ])->values()->all(),
                'documentos' => $documentos->all(),
                'sin_foliar' => $sinFoliar,
            ];
        }
        $todos = collect($secciones)->flatMap(fn (array $s): array => [...($s['documentos'] ?? []), ...($s['sin_foliar'] ?? [])]);

        return [
            'persona' => ['id' => $persona->id, 'nombre' => trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno)],
            'legajo' => ['id' => $legajo->id, 'numero' => $legajo->numero_legajo, 'estado' => $legajo->estado_legajo],
            'orden' => $orden, 'incluidos' => $incluir,
            // Acceso completo: el usuario puede consultar todas las secciones de la vista previa (nada omitido por permisos).
            'acceso_completo' => ! $accesoParcial,
            // Íntegro: ningún documento registrado de lo consultable deja de poder reproducirse.
            'integro' => $incidencias === 0,
            'resumen' => ['documentos' => $todos->count(), 'imprimibles' => $todos->where('inclusion', 'imprimible')->count(), 'incidencias' => $incidencias,
                'folios' => (int) collect($secciones)->sum(fn (array $s): int => $s['folios'] ?? 0), 'advertencias' => count($advertencias)],
            'advertencias' => $advertencias,
            'secciones' => $secciones,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $advertencias
     * @return array<string, mixed>
     */
    private static function documento(DocumentoUsuario $d, TipoDocumento $tipo, int $seccion, string $nombreSeccion, string $disco, array &$advertencias): array
    {
        $archivo = $d->archivo;
        $estadoArchivo = self::estadoArchivo($archivo?->almacenamiento === $disco ? $disco : null, $archivo?->storage_path, $archivo?->estado_antivirus);
        $folios = $d->folio_inicio !== null && $d->folio_fin !== null ? $d->folio_fin - $d->folio_inicio + 1 : null;
        $paginas = $archivo?->num_paginas;
        $anulado = $d->estado === 'anulado';
        $etiqueta = 'Folios '.($d->folio_inicio ?? '?').'–'.($d->folio_fin ?? '?').' · '.$tipo->codigo;
        if ($estadoArchivo !== 'disponible') {
            $advertencias[] = ['tipo' => 'archivo_'.$estadoArchivo, 'seccion' => $seccion, 'mensaje' => "{$etiqueta}: archivo ".self::TEXTOS_ARCHIVO[$estadoArchivo].'; no se imprime.'];
        }
        if ($folios !== null && $paginas && $folios !== (int) $paginas) {
            $advertencias[] = ['tipo' => 'folios_paginas', 'seccion' => $seccion, 'mensaje' => "{$etiqueta}: {$folios} folio(s) registrados y {$paginas} página(s) en el PDF."];
        }
        if ($anulado) {
            $advertencias[] = ['tipo' => 'anulado', 'seccion' => $seccion, 'mensaje' => "{$etiqueta}: anulado; su inclusión está pendiente de confirmar con la ORH."];
        }

        return [
            'id' => $d->id,
            'seccion' => $seccion,
            'subseccion' => $tipo->subseccionLegajo?->only(['codigo', 'nombre']),
            'tipo' => ['codigo' => $tipo->codigo, 'nombre' => $tipo->nombre],
            // Datos del registro (lo que se anotó al incorporar el documento); no se completan si faltan.
            'registro' => [
                'titulo' => $d->nombre, 'emisor' => $d->emisor, 'fecha_emision' => $d->fecha_emision?->toDateString(),
                'fecha_incorporacion' => $d->fecha_incorporacion?->toDateString(), 'num_resolucion' => $d->num_resolucion,
                'estado' => $d->estado, 'subsanado' => $d->subsanado_por > 0, 'subsana_a_otro' => $d->subsana_a_documento_id !== null,
            ],
            'folio_inicio' => $d->folio_inicio, 'folio_fin' => $d->folio_fin, 'folios' => $folios,
            // Del PDF: páginas contadas al cargarlo (ArchivoPdf::paginas) y si el archivo está hoy en el almacenamiento.
            'archivo' => ['estado' => $estadoArchivo, 'paginas' => $paginas],
            'inclusion' => $estadoArchivo !== 'disponible' ? 'no_disponible' : ($anulado ? 'por_confirmar' : 'imprimible'),
            'nombre_zip' => sprintf('%02d_%s/F%04d-%04d_%s.pdf', $seccion, Str::slug($nombreSeccion, '_'), $d->folio_inicio ?? 0, $d->folio_fin ?? 0, $tipo->codigo),
        ];
    }

    private const TEXTOS_ARCHIVO = ['ausente' => 'ausente del almacenamiento', 'infectado' => 'bloqueado por el antivirus', 'inaccesible' => 'no se pudo leer'];

    /** disponible | ausente | infectado | inaccesible */
    private static function estadoArchivo(?string $disco, ?string $ruta, ?string $antivirus): string
    {
        if ($antivirus === 'infectado') {
            return 'infectado';
        }
        if (! $disco || ! $ruta) {
            return 'ausente';
        }
        try {
            return Storage::disk($disco)->exists($ruta) ? 'disponible' : 'ausente';
        } catch (Throwable) {
            return 'inaccesible';
        }
    }

    /**
     * PDF guardados en la sección 01 antes del sistema de folios (declaraciones_verificaciones.pdf_path): se muestran
     * aparte, «sin foliar», sin asignarles folios. Solo los de tipos activos y visibles de la fase 1.
     *
     * @param  Collection<int, TipoDocumento>  $activos
     * @param  list<array<string, mixed>>  $advertencias
     * @return list<array<string, mixed>>
     */
    private static function sinFoliar(Usuario $persona, Collection $activos, string $nombreSeccion, array &$advertencias): array
    {
        $codigoDe = [];
        foreach (Seccion01::DECLARACIONES as $codigo => $tiposDeclaracion) {
            foreach ($tiposDeclaracion as $tipoDeclaracion) {
                $codigoDe[$tipoDeclaracion] = $codigo;
            }
        }
        $lista = [];
        $declaraciones = DeclaracionVerificacion::query()->where('usuario_id', $persona->id)->where('anulado', false)
            ->whereNotNull('pdf_path')->orderBy('fecha')->orderBy('id')->get();
        foreach ($declaraciones as $i => $declaracion) {
            $tipo = $activos->firstWhere('codigo', $codigoDe[$declaracion->tipo] ?? null);
            if (! $tipo) {
                continue;
            }
            $estado = self::estadoArchivo('local', $declaracion->pdf_path, null);
            if ($estado !== 'disponible') {
                $advertencias[] = ['tipo' => 'archivo_'.$estado, 'seccion' => 1, 'mensaje' => "Sin foliar · {$tipo->codigo}: archivo ".self::TEXTOS_ARCHIVO[$estado].'; no se imprime.'];
            }
            $lista[] = [
                'id' => $declaracion->id, 'seccion' => 1, 'tipo' => ['codigo' => $tipo->codigo, 'nombre' => $tipo->nombre],
                'registro' => ['titulo' => null, 'fecha' => $declaracion->fecha?->toDateString(), 'archivo_original' => $declaracion->pdf_nombre],
                'archivo' => ['estado' => $estado, 'paginas' => null],
                'inclusion' => $estado === 'disponible' ? 'imprimible' : 'no_disponible',
                'nombre_zip' => sprintf('01_%s/SF%02d_%s.pdf', Str::slug($nombreSeccion, '_'), $i + 1, $tipo->codigo),
            ];
        }

        return $lista;
    }
}
