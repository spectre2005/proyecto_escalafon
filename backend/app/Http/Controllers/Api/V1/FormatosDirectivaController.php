<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\ReferenciaLegajoPasivo;
use App\Models\SeccionLegajo;
use App\Models\VinculoLaboral;
use App\Support\Auditoria;
use App\Support\Documentos\DocumentosOperativos;
use App\Support\RegimenesLaborales;
use App\Support\VigenciaLaboral;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Formatos de la Directiva N° 001-2023-SERVIR-GDSRH (anexos 06 a 09), traídos de feature/posible_implementacion_funcional.
 * Fechas en dd/mm/aaaa y textos legibles (no códigos internos), como piden los modelos de la directiva. Los documentos
 * respetan la visibilidad de DocumentosOperativos (datos sensibles, salud, sección 07 y sanciones) y excluyen anulados y
 * falsos. Cada exportación queda en la auditoría (reporte.exportado).
 */
class FormatosDirectivaController extends Controller
{
    public const FORMATOS = [
        'matriz' => 'Matriz de relación de información de legajos (Anexo 08)',
        'caratula' => 'Carátula del legajo (Anexo 09)',
        'incorporaciones' => 'Control de incorporaciones de documentos al legajo (Anexo 06)',
        'referencias' => 'Referencia de documentos ubicados en legajo pasivo (Anexo 07)',
    ];

    private const TIPOS_DOC_IDENTIDAD = ['dni' => 'DNI', 'ce' => 'Carné de extranjería', 'pasaporte' => 'Pasaporte', 'otro' => 'Otro'];

    private const ESTADOS_DOCUMENTO = ['aceptado' => 'Aceptado', 'pendiente' => 'Pendiente', 'cargado' => 'Cargado', 'recepcionado' => 'Recepcionado',
        'en_fiscalizacion' => 'En fiscalización', 'verificado' => 'Verificado', 'observado' => 'Observado'];

    /** @var array<int, string>|null */
    private ?array $secciones = null;

    public function catalogo(): JsonResponse
    {
        return response()->json(['data' => collect(self::FORMATOS)->map(fn ($titulo, $id): array => ['id' => $id, 'titulo' => $titulo])->values()]);
    }

    /** Búsqueda de legajos por nombre, documento de identidad o número de legajo (selector del formulario). */
    public function legajos(Request $request): JsonResponse
    {
        $filtros = $request->validate(['q' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1']);

        return response()->json(Legajo::query()->with('usuario:id,nombres,apellido_paterno,apellido_materno,numero_doc_identidad')
            ->buscar($filtros['q'] ?? null)->orderBy('numero_legajo')->orderBy('id')->paginate(20, ['id', 'usuario_id', 'numero_legajo', 'estado_legajo']));
    }

    public function show(Request $request, string $formato): JsonResponse
    {
        $filtros = $request->validate(['q' => 'nullable|string|max:120', 'estado' => ['nullable', Rule::in(['activo', 'pasivo'])],
            'legajo_id' => ($formato === 'caratula' ? 'required' : 'nullable').'|uuid', 'desde' => 'nullable|date_format:Y-m-d', 'hasta' => 'nullable|date_format:Y-m-d|after_or_equal:desde',
            'seccion' => 'nullable|integer|between:1,13', 'page' => 'sometimes|integer|min:1', 'exportar' => 'sometimes|boolean']);
        $legajos = $this->consultaLegajos($filtros);
        $consulta = match ($formato) {
            'incorporaciones' => DocumentosOperativos::consulta($request, null, soloActivos: false, todas: true)->whereIn('legajo_id', (clone $legajos)->select('id'))
                ->when($filtros['desde'] ?? null, fn ($q, $d) => $q->whereDate('fecha_incorporacion', '>=', $d))->when($filtros['hasta'] ?? null, fn ($q, $d) => $q->whereDate('fecha_incorporacion', '<=', $d))
                ->when($filtros['seccion'] ?? null, fn ($q, $s) => $q->where('seccion_legajo_id', $s))->with(['legajo.usuario', 'tipoDocumento'])->orderBy('fecha_incorporacion')->orderBy('id'),
            'referencias' => ReferenciaLegajoPasivo::query()->whereIn('legajo_id', (clone $legajos)->select('id'))->whereIn('tipo_documento_id', DocumentosOperativos::tipos($request)->select('id'))
                ->when($filtros['seccion'] ?? null, fn ($q, $s) => $q->whereHas('tipoDocumento', fn ($t) => $t->where('seccion_legajo_id', $s)))
                ->with(['legajo.usuario', 'tipoDocumento', 'vinculoLaboral.regimenNormativo', 'responsableLegajo'])->orderBy('created_at')->orderBy('id'),
            default => $legajos->with(['usuario.unidadOrganica', 'usuario.regimenNormativo', 'usuario.vinculosLaborales' => fn ($q) => $q->with(['unidadOrganica', 'regimenNormativo', 'vinculoAdendas'])->orderByDesc('fecha_inicio')->orderByDesc('id')])
                ->orderBy('numero_legajo')->orderBy('id'),
        };
        if ($formato === 'caratula') {
            abort_unless((clone $consulta)->exists(), 404);
        }
        $columnas = $this->columnas($formato);
        if ($request->boolean('exportar')) {
            $filas = $consulta->get()->map(fn ($r): array => $this->fila($formato, $r));
            Auditoria::registrar($request, 'reporte.exportado', 'reportes', (string) Str::uuid(), null, null,
                ['formato' => $formato, 'filtros' => collect($filtros)->except(['page', 'exportar'])->all(), 'filas' => $filas->count()]);

            return response()->json(['data' => $filas, 'columnas' => $columnas, 'titulo' => self::FORMATOS[$formato], 'generado_en' => now('America/Lima')->format('d/m/Y H:i')])
                ->header('Cache-Control', 'private, no-store');
        }
        $pagina = $consulta->paginate(20);
        $pagina->through(fn ($r): array => $this->fila($formato, $r));

        return response()->json([...$pagina->toArray(), 'columnas' => $columnas, 'titulo' => self::FORMATOS[$formato]])->header('Cache-Control', 'private, no-store');
    }

    private function consultaLegajos(array $filtros): Builder
    {
        return Legajo::query()->when($filtros['legajo_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('estado_legajo', $v))
            ->buscar($filtros['q'] ?? null)->whereHas('usuario');
    }

    private function columnas(string $tipo): array
    {
        $comunes = ['numero_legajo' => 'N.º de legajo', 'persona' => 'Apellidos y nombres'];

        return match ($tipo) {
            'incorporaciones' => [...$comunes, 'motivo' => 'Motivo de incorporación', 'documento' => 'Documento incorporado', 'seccion' => 'Sección a la que corresponde', 'folios' => 'Folios', 'fecha' => 'Fecha de incorporación', 'estado' => 'Estado'],
            'referencias' => [...$comunes, 'seccion' => 'Sección', 'puesto' => 'Cargo ocupado', 'regimen' => 'Régimen de vinculación', 'cese' => 'Fecha de cese', 'documento' => 'Tipo de documento registrado en legajo pasivo',
                'folios' => 'Folios en los que se ubica el documento a referenciar', 'fedateado' => 'Documento fedateado (SÍ/NO)', 'fecha_fedateo' => 'Fecha de fedateo', 'fiscalizado' => 'Documento fiscalizado (SÍ/NO)',
                'fecha_fiscalizacion' => 'Fecha de fiscalización', 'responsable' => 'Responsable de legajo', 'dni_responsable' => 'N.º de DNI del responsable', 'firma' => 'Firma del responsable'],
            'caratula' => [...$comunes, 'puesto' => 'Nombre del puesto', 'ingreso' => 'Fecha de ingreso', 'cese' => 'Fecha de cese', 'documento_identidad' => 'N.º de DNI'],
            default => [...$comunes, 'tipo_documento_identidad' => 'Tipo de documento de identidad', 'documento_identidad' => 'N.º de documento de identidad', 'puesto' => 'Nombre del puesto',
                'apertura' => 'Fecha de apertura del legajo', 'regimen' => 'Régimen laboral', 'unidad' => 'Órgano o unidad orgánica', 'estado' => 'Estado del legajo', 'ubicacion' => 'Ubicación del legajo'],
        };
    }

    private function fila(string $tipo, mixed $registro): array
    {
        $l = $registro instanceof Legajo ? $registro : $registro->legajo;
        $p = $l->usuario;
        $comunes = ['numero_legajo' => $l->numero_legajo, 'persona' => trim($p->apellido_paterno.' '.$p->apellido_materno).', '.$p->nombres];
        if ($registro instanceof DocumentoUsuario) {
            return [...$comunes, 'motivo' => $registro->motivo_incorporacion, 'documento' => $registro->nombre ?: $registro->tipoDocumento?->nombre, 'seccion' => $this->seccion($registro->seccion_legajo_id),
                'folios' => $this->folios($registro->folio_inicio, $registro->folio_fin), 'fecha' => $registro->fecha_incorporacion?->format('d/m/Y'), 'estado' => self::ESTADOS_DOCUMENTO[$registro->estado] ?? $registro->estado];
        }
        if ($registro instanceof ReferenciaLegajoPasivo) {
            $v = $registro->vinculoLaboral;

            return [...$comunes, 'seccion' => $this->seccion($registro->tipoDocumento?->seccion_legajo_id), 'puesto' => $v?->nombre_puesto, 'regimen' => $this->regimen($v), 'cese' => $v?->fecha_cese?->format('d/m/Y'),
                'documento' => $registro->tipoDocumento?->nombre, 'folios' => $this->folios($registro->folio_inicio_pasivo, $registro->folio_fin_pasivo),
                'fedateado' => $registro->fedateado ? 'SÍ' : 'NO', 'fecha_fedateo' => $registro->fecha_fedateo?->format('d/m/Y'), 'fiscalizado' => $registro->fiscalizado ? 'SÍ' : 'NO', 'fecha_fiscalizacion' => $registro->fecha_fiscalizacion?->format('d/m/Y'),
                'responsable' => trim(($registro->responsableLegajo?->nombres ?? '').' '.($registro->responsableLegajo?->apellido_paterno ?? '').' '.($registro->responsableLegajo?->apellido_materno ?? '')) ?: null,
                'dni_responsable' => $registro->responsableLegajo?->numero_doc_identidad, 'firma' => '____________________'];
        }
        // Activo: el vínculo vigente hoy; pasivo: el último iniciado antes del pase a pasivo.
        $v = $p->vinculosLaborales->first(fn ($v) => $l->estado_legajo === 'activo' ? VigenciaLaboral::vigente($v) : ! $v->anulado && $v->fecha_inicio->format('Y-m-d') <= ($l->fecha_pasivo?->format('Y-m-d') ?? VigenciaLaboral::hoy()));
        $puesto = $v?->nombre_puesto ?? $p->nombre_puesto;

        return $tipo === 'caratula'
            ? [...$comunes, 'puesto' => $puesto, 'ingreso' => $v?->fecha_inicio?->format('d/m/Y'), 'cese' => $v?->fecha_cese?->format('d/m/Y') ?? $l->fecha_pasivo?->format('d/m/Y'), 'documento_identidad' => $p->numero_doc_identidad]
            : [...$comunes, 'tipo_documento_identidad' => self::TIPOS_DOC_IDENTIDAD[$p->tipo_doc_identidad] ?? $p->tipo_doc_identidad, 'documento_identidad' => $p->numero_doc_identidad, 'puesto' => $puesto,
                'apertura' => $l->fecha_apertura?->format('d/m/Y'), 'regimen' => $this->regimen($v) ?? $p->regimenNormativo?->nombre, 'unidad' => $v?->unidadOrganica?->nombre ?? $p->unidadOrganica?->nombre,
                'estado' => $l->estado_legajo === 'activo' ? 'Activo' : 'Pasivo', 'ubicacion' => $l->ubicacion];
    }

    /** Régimen laboral según la directiva: la ley del vínculo (276, 728, CAS…) y, si se conoce, su tipo de vínculo. */
    private function regimen(?VinculoLaboral $vinculo): ?string
    {
        if (! $vinculo) {
            return null;
        }
        $partes = array_filter([$vinculo->regimenNormativo?->nombre, RegimenesLaborales::TIPOS_VINCULO[$vinculo->regimen_laboral] ?? null]);

        return $partes ? implode(' · ', $partes) : null;
    }

    private function seccion(mixed $id): ?string
    {
        $this->secciones ??= SeccionLegajo::query()->pluck('nombre', 'id')->all();

        return $id === null ? null : rtrim(sprintf('%02d %s', (int) $id, $this->secciones[(int) $id] ?? ''));
    }

    private function folios(mixed $inicio, mixed $fin): ?string
    {
        return $inicio === null ? null : ($fin === null || (int) $fin === (int) $inicio ? "folio {$inicio}" : "folios {$inicio} al {$fin}");
    }
}
