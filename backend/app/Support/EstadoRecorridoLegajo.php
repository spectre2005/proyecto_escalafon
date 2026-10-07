<?php

namespace App\Support;

use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\RecorridoLegajo;
use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Illuminate\Support\Collection;

/** Estado único del registro; la recepción y aprobación permanecen en sus servicios existentes. */
final class EstadoRecorridoLegajo
{
    public static function calcular(Legajo $legajo, Usuario $actor, ?RecorridoLegajo $recorrido = null): array
    {
        $persona = Usuario::query()->with('vinculosLaborales')->findOrFail($legajo->usuario_id);
        $perfil = PerfilDocumental::de($persona, $persona->vinculosLaborales);
        $familiares = Familiar::query()->where('usuario_id', $persona->id)->where('anulado', false)->get();
        $porFamiliar = $familiares->map(fn (Familiar $familiar): ?array => ExigenciasSeccion01::documentoDeFamiliar($familiar, today()))->filter()->all();
        $documentos = DocumentoUsuario::query()->where('legajo_id', $legajo->id)->where('usuario_id', $persona->id)->with('archivo')->get();
        $secciones = SeccionLegajo::query()->whereIn('id', config('legajo.secciones_registro_guiado', []))
            ->whereHas('legajoSecciones', fn ($query) => $query->where('legajo_id', $legajo->id))
            ->with(['tiposDocumento' => fn ($query) => $query->where('activo', true)->orderBy('orden')->orderBy('id'), 'tiposDocumento.subseccionLegajo'])
            ->orderBy('orden')->orderBy('id')->get()->map(function (SeccionLegajo $seccion) use ($perfil, $familiares, $porFamiliar, $documentos, $actor, $recorrido): array {
                $base = ['id' => $seccion->id, 'nombre' => $seccion->nombre, 'orden' => $seccion->orden];
                if (! self::accesoSeccion($actor, $seccion->id)) {
                    return [...$base, 'estado' => 'restringida', 'items' => [], 'puede_continuar' => false, 'solo_opcionales' => false,
                        'motivo' => 'Tu perfil requiere acceso a esta sección. Solicita apoyo a un usuario autorizado.'];
                }
                $items = [];
                foreach ($seccion->tiposDocumento as $tipo) {
                    if (! self::accesoTipo($actor, $tipo)) {
                        $items[] = ['id' => $tipo->id, 'codigo' => $tipo->codigo, 'nombre' => 'Documento con acceso restringido',
                            'estado' => 'restringido', 'exigido' => null, 'aplica' => true, 'registrado' => false, 'documentos' => [],
                            'motivo' => 'Se requiere permiso para este documento.', 'subseccion' => null, 'bloque' => null];

                        continue;
                    }
                    $exigencia = ExigenciasDocumentales::tipo($tipo, $perfil, $familiares, $porFamiliar);
                    $lista = $documentos->where('tipo_documento_id', $tipo->id);
                    if ($seccion->id === 1 && isset(Seccion01::PARENTESCOS[$tipo->codigo])) {
                        $exigencias = $familiares->map(function (Familiar $familiar) use ($tipo): ?array {
                            $exigencia = ExigenciasSeccion01::documentoDeFamiliar($familiar, today());

                            return $exigencia && $exigencia['codigo'] === $tipo->codigo ? ['familiar' => $familiar, 'exigencia' => $exigencia] : null;
                        })->filter();
                        if ($exigencias->isNotEmpty()) {
                            foreach ($exigencias as $fila) {
                                $items[] = self::item($tipo, [...$fila['exigencia'], 'aplica' => true], ExigenciasSeccion01::cadena($fila['familiar']->documento_id, $lista),
                                    $fila['familiar']->apellidos_nombres, $fila['familiar']->id);
                            }

                            continue;
                        }
                    }
                    $items[] = self::item($tipo, $exigencia, $lista);
                }
                $aplicables = collect($items)->where('aplica', true);
                $exigidos = $aplicables->whereStrict('exigido', true);
                $faltantes = $exigidos->where('registrado', false)->count();
                $indeterminados = $aplicables->whereNull('exigido')->count();
                $errores = $aplicables->sum(fn (array $item): int => count(array_filter($item['documentos'], fn (array $doc): bool => $doc['errores'] !== [])));
                $restringida = $aplicables->contains('estado', 'restringido');
                $soloOpcionales = $items !== [] && $aplicables->isNotEmpty() && $exigidos->isEmpty() && $indeterminados === 0;
                $noAplica = $items !== [] && $aplicables->isEmpty();
                $puede = $items !== [] && ! $restringida && $faltantes === 0 && $indeterminados === 0 && $errores === 0;
                $registrados = $aplicables->where('registrado', true)->count();
                $estado = match (true) {
                    $items === [] => 'sin_configurar',
                    $restringida => 'restringida',
                    $noAplica => 'no_aplica',
                    ! $puede => 'pendiente',
                    $exigidos->isNotEmpty() || $registrados > 0 => 'completa',
                    in_array($seccion->id, $recorrido?->secciones_omitidas ?? [], true) => 'omitida',
                    default => 'pendiente',
                };
                $motivo = match (true) {
                    $items === [] => 'Esta sección no tiene tipos documentales activos. Solicita revisar el catálogo.',
                    $restringida => 'Hay documentos con acceso restringido. Solicita apoyo a un usuario autorizado.',
                    $noAplica => 'Esta sección no aplica para tu puesto.',
                    $errores > 0 => 'Hay cargas que requieren verificar su archivo o foliación. Revisa el detalle antes de continuar.',
                    $indeterminados > 0 => 'Faltan datos del perfil o de familiares para determinar los requisitos. Complétalos en sus pantallas.',
                    $faltantes > 0 => 'Registra los documentos obligatorios indicados para continuar.',
                    $soloOpcionales => 'Puedes agregar documentos opcionales o continuar más adelante.',
                    default => 'Los documentos obligatorios están registrados. Puedes continuar.',
                };

                return [...$base, 'estado' => $estado, 'items' => $items, 'puede_continuar' => $puede, 'solo_opcionales' => $soloOpcionales,
                    'motivo' => $motivo, 'obligatorios' => $exigidos->count(), 'faltantes' => $faltantes];
            })->values();
        $items = $secciones->flatMap(fn (array $seccion): array => $seccion['items']);
        $obligatorios = $items->whereStrict('exigido', true)->where('aplica', true);

        return [
            'legajo_id' => $legajo->id, 'iniciado' => $recorrido !== null, 'seccion_actual_id' => $recorrido?->seccion_actual_id,
            'secciones' => $secciones->all(), 'total_secciones' => $secciones->count(),
            'resumen' => [
                'obligatorios' => $obligatorios->count(), 'obligatorios_completos' => $obligatorios->where('registrado', true)->count(),
                'obligatorios_pendientes' => $obligatorios->where('registrado', false)->count(),
                'por_determinar' => $items->whereNull('exigido')->where('aplica', true)->count(),
                'documentos_pendientes_revision' => $items->flatMap(fn (array $item): array => $item['documentos'])->unique('id')->whereIn('estado', ['pendiente', 'cargado'])->count(),
                'documentos_observados' => $items->flatMap(fn (array $item): array => $item['documentos'])->unique('id')->where('estado', 'observado')->count(),
                'opcionales_omitidos' => $secciones->filter(fn (array $seccion): bool => in_array($seccion['id'], $recorrido?->secciones_omitidas ?? [], true))
                    ->sum(fn (array $seccion): int => collect($seccion['items'])->whereStrict('exigido', false)->where('aplica', true)->where('registrado', false)->count()),
                'secciones_restringidas' => $secciones->where('estado', 'restringida')->count(),
                'secciones_resueltas' => $secciones->whereIn('estado', ['completa', 'omitida', 'no_aplica'])->count(),
            ],
        ];
    }

    public static function accesoSeccion(Usuario $actor, int $seccion): bool
    {
        return (! in_array($seccion, [1, 9, 10], true) || $actor->veDatosSensibles())
            && ($seccion !== 7 || ($actor->can('seccion07.ver') && $actor->can('seccion07.registrar')));
    }

    public static function accesoTipo(Usuario $actor, TipoDocumento $tipo): bool
    {
        return (! ($tipo->es_dato_sensible || $tipo->es_dato_salud) || $actor->veDatosSensibles())
            && (! in_array($tipo->codigo, ['SAN_RESOLUCION_SANCION', 'SAN_RESOLUCION_RECURSO'], true)
                || ($actor->can('seccion08.sanciones.registrar') && ($actor->can('seccion08.sanciones.ver') || $actor->can('seccion08.sanciones.revisar'))));
    }

    private static function item(TipoDocumento $tipo, array $exigencia, Collection $documentos, ?string $nombre = null, ?string $familiarId = null): array
    {
        $vigentes = $documentos->reject(fn (DocumentoUsuario $doc): bool => ! in_array($doc->estado, VerificacionRegistroDocumento::ESTADOS_REGISTRADOS, true)
            || $documentos->contains(fn (DocumentoUsuario $otro): bool => $otro->subsana_a_documento_id === $doc->id && $otro->estado !== 'anulado'));
        $lista = $vigentes->map(fn (DocumentoUsuario $doc): array => ['id' => $doc->id, 'estado' => $doc->estado,
            'errores' => VerificacionRegistroDocumento::errores($doc)])->values();
        $registrado = $lista->contains(fn (array $doc): bool => $doc['errores'] === []);

        return [
            'id' => $tipo->id, 'codigo' => $tipo->codigo, 'nombre' => $tipo->nombre.($nombre ? ' · '.$nombre : ''),
            'familiar_id' => $familiarId, 'subseccion' => $tipo->subseccionLegajo?->codigo,
            'bloque' => match ($tipo->seccion_legajo_id) {
                5 => Seccion05::bloque($tipo->codigo), 9 => Seccion09::bloque($tipo->codigo), 11 => Seccion11::bloque($tipo->codigo), default => null
            },
            ...$exigencia, 'registrado' => $registrado, 'documentos' => $lista->all(),
            'estado' => match (true) {
                ! $exigencia['aplica'] => 'no_aplica', $exigencia['exigido'] === null => 'por_determinar',
                $registrado => 'registrado', $exigencia['exigido'] => 'pendiente', default => 'opcional'
            },
        ];
    }
}
