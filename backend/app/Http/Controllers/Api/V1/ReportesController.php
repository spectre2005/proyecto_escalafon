<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\ReferenciaLegajoPasivo;
use App\Support\AuditoriaInstitucional;
use App\Support\Documentos\DocumentosOperativos;
use App\Support\VigenciaLaboral;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReportesController extends Controller
{
    public const FORMATOS = ['caratula' => 'Carátula del legajo (Anexo 09)', 'incorporaciones' => 'Control de incorporación de documentos (Anexo 06)', 'matriz' => 'Matriz de legajos (Anexo 08)', 'referencias' => 'Referencia al legajo pasivo (Anexo 07)'];

    public function catalogo(): JsonResponse
    {
        return response()->json(['data' => collect(self::FORMATOS)->map(fn ($titulo, $id): array => ['id' => $id, 'titulo' => $titulo])->values()]);
    }

    public function show(Request $request, string $formato): JsonResponse
    {
        abort_unless(isset(self::FORMATOS[$formato]), 404);
        $filtros = $request->validate(['q' => 'nullable|string|max:120', 'estado' => ['nullable', Rule::in(['activo', 'pasivo'])], 'unidad' => 'nullable|uuid', 'regimen' => 'nullable|string|max:100',
            'legajo_id' => ($formato === 'caratula' ? 'required' : 'nullable').'|uuid', 'desde' => 'nullable|date_format:Y-m-d', 'hasta' => 'nullable|date_format:Y-m-d|after_or_equal:desde', 'seccion' => 'nullable|integer|between:1,13', 'page' => 'sometimes|integer|min:1', 'exportar' => 'sometimes|boolean']);
        $legajos = $this->legajos($filtros);
        $consulta = match ($formato) {
            'incorporaciones' => DocumentosOperativos::consulta($request, null, soloActivos: false, todas: true)->whereIn('legajo_id', (clone $legajos)->select('id'))
                ->when($filtros['desde'] ?? null, fn ($q, $d) => $q->whereDate('fecha_incorporacion', '>=', $d))->when($filtros['hasta'] ?? null, fn ($q, $d) => $q->whereDate('fecha_incorporacion', '<=', $d))
                ->when($filtros['seccion'] ?? null, fn ($q, $s) => $q->where('seccion_legajo_id', $s))->with(['legajo.usuario', 'tipoDocumento'])->orderBy('fecha_incorporacion')->orderBy('id'),
            'referencias' => ReferenciaLegajoPasivo::query()->whereIn('legajo_id', (clone $legajos)->select('id'))->whereIn('tipo_documento_id', DocumentosOperativos::tipos($request)->select('id'))
                ->with(['legajo.usuario', 'tipoDocumento', 'vinculoLaboral', 'legajoOrigen', 'responsableLegajo'])->orderBy('created_at')->orderBy('id'),
            default => $legajos->with(['usuario.unidadOrganica', 'usuario.vinculosLaborales' => fn ($q) => $q->with(['unidadOrganica', 'vinculoAdendas'])->orderByDesc('fecha_inicio')->orderByDesc('id')])->orderBy('numero_legajo')->orderBy('id'),
        };
        if ($formato === 'caratula') {
            abort_unless((clone $consulta)->exists(), 404);
        }
        $columnas = $this->columnas($formato);
        if ($request->boolean('exportar')) {
            $filas = $consulta->get()->map(fn ($r): array => $this->fila($formato, $r));
            AuditoriaInstitucional::registrar('reportes', (string) Str::uuid(), 'reporte.exportado', $request, null, null, ['formato' => $formato, 'filtros' => collect($filtros)->except(['page', 'exportar'])->all(), 'filas' => $filas->count()]);

            return response()->json(['data' => $filas, 'columnas' => $columnas, 'titulo' => self::FORMATOS[$formato], 'generado_en' => now()->toIso8601String()])->header('Cache-Control', 'private, no-store');
        }
        $pagina = $consulta->paginate(20);
        $pagina->through(fn ($r): array => $this->fila($formato, $r));

        return response()->json([...$pagina->toArray(), 'columnas' => $columnas, 'titulo' => self::FORMATOS[$formato]])->header('Cache-Control', 'private, no-store');
    }

    private function legajos(array $filtros): Builder
    {
        return Legajo::query()->when($filtros['legajo_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('estado_legajo', $v))
            ->buscar($filtros['q'] ?? null)
            ->when(($filtros['unidad'] ?? null) || ($filtros['regimen'] ?? null), fn ($q) => $q->whereHas('usuario.vinculosLaborales', fn ($v) => $v->vigente()->when($filtros['unidad'] ?? null, fn ($v, $id) => $v->where('unidad_organica_id', $id))->when($filtros['regimen'] ?? null, fn ($v, $r) => $v->where('regimen_laboral', $r))));
    }

    private function columnas(string $tipo): array
    {
        $comunes = ['numero_legajo' => 'N.º legajo', 'persona' => 'Apellidos y nombres', 'documento_identidad' => 'Documento de identidad'];

        return match ($tipo) {
            'incorporaciones' => [...$comunes, 'motivo' => 'Motivo de incorporación', 'documento' => 'Documento incorporado', 'seccion' => 'Sección', 'folios' => 'Folios', 'fecha' => 'Fecha de incorporación', 'estado' => 'Estado'],
            'referencias' => [...$comunes, 'legajo_origen' => 'Legajo pasivo', 'ubicacion' => 'Ubicación del pasivo', 'seccion' => 'Sección de referencia', 'puesto' => 'Puesto anterior', 'regimen' => 'Régimen anterior', 'cese' => 'Fecha de cese', 'documento' => 'Documento', 'folios' => 'Folios originales', 'fedateado' => 'Fedateado', 'fecha_fedateo' => 'Fecha de fedateo', 'fiscalizado' => 'Fiscalizado', 'fecha_fiscalizacion' => 'Fecha de fiscalización', 'responsable' => 'Responsable', 'dni_responsable' => 'DNI responsable', 'firma' => 'Firma del responsable'],
            'caratula' => [...$comunes, 'puesto' => 'Puesto', 'unidad' => 'Unidad orgánica', 'ingreso' => 'Fecha de ingreso', 'cese' => 'Fecha de cese', 'confidencial' => 'Clasificación'],
            default => [...$comunes, 'puesto' => 'Puesto', 'regimen' => 'Régimen laboral', 'unidad' => 'Unidad orgánica', 'apertura' => 'Fecha de apertura', 'estado' => 'Estado del legajo', 'ubicacion' => 'Ubicación'],
        };
    }

    private function fila(string $tipo, mixed $registro): array
    {
        $l = $registro instanceof Legajo ? $registro : $registro->legajo;
        $p = $l->usuario;
        $comunes = ['numero_legajo' => $l->numero_legajo, 'persona' => trim($p->apellido_paterno.' '.$p->apellido_materno.', '.$p->nombres), 'documento_identidad' => $p->numero_doc_identidad];
        if ($registro instanceof DocumentoUsuario) {
            return [...$comunes, 'motivo' => $registro->motivo_incorporacion, 'documento' => $registro->nombre ?: $registro->tipoDocumento?->nombre, 'seccion' => $registro->seccion_legajo_id, 'folios' => $registro->folio_inicio.'–'.$registro->folio_fin, 'fecha' => $registro->fecha_incorporacion?->format('Y-m-d'), 'estado' => $registro->estado];
        }
        if ($registro instanceof ReferenciaLegajoPasivo) {
            return [...$comunes, 'legajo_origen' => $registro->legajoOrigen?->numero_legajo, 'ubicacion' => $registro->legajoOrigen?->ubicacion, 'seccion' => 13, 'puesto' => $registro->vinculoLaboral?->nombre_puesto, 'regimen' => $registro->vinculoLaboral?->regimen_laboral, 'cese' => $registro->vinculoLaboral?->fecha_cese?->format('Y-m-d'), 'documento' => $registro->tipoDocumento?->nombre, 'folios' => $registro->folio_inicio_pasivo.'–'.$registro->folio_fin_pasivo,
                'fedateado' => $registro->fedateado ? 'Sí' : 'No', 'fecha_fedateo' => $registro->fecha_fedateo?->format('Y-m-d'), 'fiscalizado' => $registro->fiscalizado ? 'Sí' : 'No', 'fecha_fiscalizacion' => $registro->fecha_fiscalizacion?->format('Y-m-d'),
                'responsable' => trim(($registro->responsableLegajo?->nombres ?? '').' '.($registro->responsableLegajo?->apellido_paterno ?? '')), 'dni_responsable' => $registro->responsableLegajo?->numero_doc_identidad, 'firma' => '____________________'];
        }
        $v = $p->vinculosLaborales->first(fn ($v) => $l->estado_legajo === 'activo' ? VigenciaLaboral::vigente($v) : ! $v->anulado && $v->fecha_inicio->format('Y-m-d') <= ($l->fecha_pasivo?->format('Y-m-d') ?? VigenciaLaboral::hoy()));
        $puesto = ['puesto' => $v?->nombre_puesto ?? $p->nombre_puesto, 'unidad' => $v?->unidadOrganica?->nombre ?? $p->unidadOrganica?->nombre];

        return $tipo === 'caratula'
            ? [...$comunes, ...$puesto, 'ingreso' => $v?->fecha_inicio?->format('Y-m-d'), 'cese' => $v?->fecha_cese?->format('Y-m-d') ?? $l->fecha_pasivo?->format('Y-m-d'), 'confidencial' => 'CONFIDENCIAL']
            : [...$comunes, ...$puesto, 'regimen' => $v?->regimen_laboral, 'apertura' => $l->fecha_apertura?->format('Y-m-d'), 'estado' => $l->estado_legajo, 'ubicacion' => $l->ubicacion];
    }
}
