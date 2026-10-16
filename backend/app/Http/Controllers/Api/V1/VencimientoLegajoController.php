<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BitacoraAccesoLegajo;
use App\Models\CicloVigencia;
use App\Models\DocumentoUsuario;
use App\Models\HistorialEstadoDocumento;
use App\Models\Legajo;
use App\Models\PerfilDocente;
use App\Models\PresentacionRenovacion;
use App\Models\ProcesoFiscalizacion;
use App\Models\RenovacionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\AvisosVencimiento;
use App\Support\Documentos\Antivirus;
use App\Support\Documentos\ArchivoPdf;
use App\Support\Documentos\AuditoriaDocumento;
use App\Support\Documentos\EstadoDocumento;
use App\Support\Parametros;
use App\Support\VigenciaLegajo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VencimientoLegajoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->autorizarPanel($request);
        $filtros = $request->validate(['q' => 'nullable|string|max:120', 'categoria' => ['nullable', Rule::in(array_keys(VigenciaLegajo::ANIOS))],
            'estado' => 'nullable|in:vigente,por_vencer,vencido,revision', 'desde' => 'nullable|date_format:Y-m-d', 'hasta' => 'nullable|date_format:Y-m-d|after_or_equal:desde', 'page' => 'sometimes|integer|min:1']);
        $query = Legajo::query()->where('estado_legajo', 'activo')->whereHas('usuario', fn ($q) => $q->where('tipo_personal', 'docente'));
        if (! $request->user()->can('vencimientos.ver')) {
            $query->where('usuario_id', $request->user()->id);
        }
        if (! empty($filtros['q'])) {
            $buscar = '%'.mb_strtolower(trim($filtros['q'])).'%';
            $query->whereHas('usuario', fn ($q) => $q->whereRaw("LOWER(nombres || ' ' || apellido_paterno || ' ' || COALESCE(apellido_materno, '')) LIKE ?", [$buscar])->orWhere('numero_doc_identidad', 'like', $buscar));
        }
        if (! empty($filtros['categoria'])) {
            $query->where(fn ($q) => $q->whereHas('cicloActual', fn ($c) => $c->where('categoria', $filtros['categoria']))
                ->orWhere(fn ($q) => $q->whereDoesntHave('cicloActual')->whereHas('usuario.vinculosLaborales.perfilDocente', fn ($p) => $p->where('categoria', $filtros['categoria']))));
        }
        $hoy = VigenciaLegajo::hoy()->toDateString();
        $en30 = VigenciaLegajo::hoy()->addDays(30)->toDateString();
        if (($filtros['estado'] ?? null) === 'revision') {
            $query->whereDoesntHave('cicloActual');
        } elseif (! empty($filtros['estado'])) {
            $query->whereHas('cicloActual', function ($q) use ($filtros, $hoy, $en30): void {
                match ($filtros['estado']) {
                    'vencido' => $q->whereDate('fecha_vencimiento', '<=', $hoy),
                    'por_vencer' => $q->whereDate('fecha_vencimiento', '>', $hoy)->whereDate('fecha_vencimiento', '<=', $en30),
                    'vigente' => $q->whereDate('fecha_vencimiento', '>', $en30),
                };
            });
        }
        foreach (['desde' => '>=', 'hasta' => '<='] as $campo => $operador) {
            if (! empty($filtros[$campo])) {
                $query->whereHas('cicloActual', fn ($q) => $q->whereDate('fecha_vencimiento', $operador, $filtros[$campo]));
            }
        }
        $resumen = [
            'por_vencer' => (clone $query)->whereHas('cicloActual', fn ($q) => $q->whereDate('fecha_vencimiento', '>', $hoy)->whereDate('fecha_vencimiento', '<=', $en30))->count(),
            'vencidos' => (clone $query)->whereHas('cicloActual', fn ($q) => $q->whereDate('fecha_vencimiento', '<=', $hoy))->count(),
            'revision' => (clone $query)->whereDoesntHave('cicloActual')->count(),
        ];
        $records = $query->with(['usuario', 'cicloActual.renovacion', 'cicloActual.requerimientos'])
            ->orderBy(CicloVigencia::query()->select('fecha_vencimiento')->whereColumn('legajo_id', 'legajos.id')->where('actual', true)->limit(1))
            ->orderBy('id')->paginate(20);
        $records->through(fn (Legajo $legajo) => $this->fila($legajo, $request));

        return response()->json([...$records->toArray(), 'resumen' => $resumen, 'hoy' => $hoy,
            'correo_prueba' => in_array(config('mail.default'), ['log', 'array'], true)]);
    }

    public function show(Request $request, Legajo $legajo): JsonResponse
    {
        $this->autorizarLegajo($request, $legajo);
        $ciclo = $legajo->cicloActual;
        $data = $this->fila($legajo, $request);
        $data['hoy'] = VigenciaLegajo::hoy()->toDateString();
        $data['perfiles'] = VigenciaLegajo::perfiles($legajo)->map(fn ($p) => ['id' => $p->id, 'categoria' => $p->categoria, 'puesto' => $p->vinculoLaboral->nombre_puesto])->all();
        $data['resoluciones'] = $request->user()->can('vencimientos.gestionar') ? $legajo->documentosUsuario()->whereIn('seccion_legajo_id', [2, 7])
            ->whereNotNull('num_resolucion')->with(['tipoDocumento', 'archivo'])->get()->filter(fn ($d) => $this->puedeTipo($request, $d->tipoDocumento, $legajo) && VigenciaLegajo::evidenciaAprobada($d))
            ->filter(fn ($d) => VigenciaLegajo::perfiles($legajo)->contains(fn ($p) => VigenciaLegajo::resolucionAplicable($d, $p)))
            ->map(fn ($d) => ['id' => $d->id, 'numero' => $d->num_resolucion, 'fecha' => $d->fecha_emision?->toDateString()])->values()->all() : [];
        $data['requisitos'] = [];
        if ($ciclo) {
            $data['renovacion'] = $ciclo->renovacion;
            $data['documento_base'] = $ciclo->documentoBase ? ['id' => $ciclo->documento_base_id, 'numero' => $ciclo->documentoBase->num_resolucion] : null;
            $data['requisitos'] = $ciclo->requerimientos()->with(['tipoDocumento.seccionLegajo', 'presentacion', 'documento'])->get()->map(function ($r) use ($request, $legajo): array {
                $tipo = TipoDocumento::withoutGlobalScopes()->with('seccionLegajo')->find($r->tipo_documento_id);
                $permitido = $this->puedeTipo($request, $tipo, $legajo);
                $habilitado = $tipo?->activo && $tipo->seccionLegajo?->activa && ($tipo->seccion_legajo_id !== 9 || $tipo->codigo === 'S09_C_AFILIACION_SINDICATO');
                $documentos = $permitido && $request->user()->can('renovaciones.aprobar') ? $legajo->documentosUsuario()->where('tipo_documento_id', $tipo->id)
                    ->where('estado', '!=', 'anulado')->with(['tipoDocumento', 'archivo'])->get()->filter(fn ($d) => $d->id !== $r->documento_objetivo_id && (VigenciaLegajo::evidenciaAprobada($d)
                        || ($d->tipoDocumento?->acredita_perfil_puesto && EstadoDocumento::recepcionado($d) && ! in_array($d->estado, ['pendiente', 'observado', 'falso'], true))))
                    ->map(fn ($d) => ['id' => $d->id, 'fecha' => $d->fecha_emision?->toDateString(), 'numero' => $d->num_resolucion,
                        'cargado_por_revisor' => $d->created_by === $request->user()->id || $d->archivo?->subido_por === $request->user()->id,
                        'necesita_fiscalizacion' => $d->tipoDocumento->acredita_perfil_puesto && ! VigenciaLegajo::evidenciaAprobada($d)])->values()->all() : [];

                return ['id' => $r->id, 'nombre' => $permitido ? $tipo?->nombre : 'Documento de acceso restringido',
                    'seccion' => $permitido ? $tipo?->seccion_legajo_id : null, 'motivo' => $permitido ? $r->motivo_renovacion : null,
                    'estado' => $r->estado, 'permitido' => $permitido, 'habilitado' => (bool) $habilitado,
                    'documento_id' => $permitido ? $r->documento_id : null, 'presentacion' => $permitido && $r->presentacion ? ['id' => $r->presentacion->id, 'nombre' => $r->presentacion->nombre_original, 'fecha_vencimiento' => $r->presentacion->fecha_vencimiento?->toDateString()] : null,
                    'documentos' => $documentos];
            })->all();
            $data['avisos'] = $ciclo->avisos()->with('envios')->latest()->get()->map(fn ($a) => ['id' => $a->id, 'origen' => $a->origen,
                'fecha_programada' => $a->fecha_programada->toDateString(), 'creado_en' => $a->created_at,
                'envios' => $a->envios->filter(fn ($e) => $request->user()->can('vencimientos.notificar') || $e->destinatario_id === $request->user()->id)
                    ->map(fn ($e) => $e->only(['id', 'estado', 'intentos', 'enviado_en', 'error']))->values()])->all();
            $data['informes'] = $request->user()->can('documento.fiscalizar') ? $legajo->documentosUsuario()->where('estado', '!=', 'anulado')
                ->with(['tipoDocumento', 'archivo'])->get()->filter(fn ($d) => $this->puedeTipo($request, $d->tipoDocumento, $legajo)
                    && ! $d->tipoDocumento?->acredita_perfil_puesto && VigenciaLegajo::evidenciaAprobada($d))
                ->map(fn ($d) => ['id' => $d->id, 'nombre' => $d->tipoDocumento->nombre, 'fecha' => $d->fecha_emision?->toDateString(), 'numero' => $d->num_resolucion])->values()->all() : [];
        }

        return response()->json(['data' => $data]);
    }

    public function opciones(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('vencimientos.gestionar'), 403);
        $tipos = TipoDocumento::query()->where('activo', true)->with('seccionLegajo')->orderBy('seccion_legajo_id')->orderBy('nombre')->get()
            ->filter(fn ($t) => $t->seccionLegajo?->activa && $this->puedeTipo($request, $t))->map(fn ($t) => $t->only(['id', 'nombre', 'seccion_legajo_id']))->values();

        return response()->json(['data' => ['categorias' => VigenciaLegajo::ANIOS, 'tipos' => $tipos,
            'politicas' => DB::table('politicas_renovacion')->where('actual', true)->get(),
            'jefes' => Usuario::query()->where('activo', true)->orderBy('apellido_paterno')->get(['id', 'nombres', 'apellido_paterno', 'apellido_materno'])]]);
    }

    public function procesar(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('vencimientos.gestionar'), 403);
        $resultado = Artisan::call('legajos:controlar-vencimientos');

        return response()->json(['message' => $resultado === 0 ? 'Control de vencimientos completado.' : 'El control terminó con incidencias. Revisa los datos y el registro de errores.',
            'data' => Cache::get('vencimientos.ultimo_control')]);
    }

    public function politica(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('vencimientos.gestionar'), 403);
        $data = $request->validate(['categoria' => ['required', Rule::in(array_keys(VigenciaLegajo::ANIOS))], 'tipo_documento_id' => 'required|uuid|exists:tipos_documento,id',
            'modo' => 'required|in:por_ciclo,si_falta,por_vencimiento', 'antiguedad_maxima_dias' => 'nullable|integer|min:1|max:36500']);
        $tipo = TipoDocumento::query()->findOrFail($data['tipo_documento_id']);
        $this->exigirTipo($request, $tipo);
        DB::transaction(function () use ($data, $request): void {
            DB::table('reglas_vigencia_categoria')->where('categoria', $data['categoria'])->lockForUpdate()->first();
            $query = DB::table('politicas_renovacion')->where('categoria', $data['categoria'])->where('tipo_documento_id', $data['tipo_documento_id']);
            $version = (clone $query)->max('version') ?? 0;
            (clone $query)->where('actual', true)->update(['actual' => false, 'updated_at' => now()]);
            DB::table('politicas_renovacion')->insert([...$data, 'id' => (string) Str::uuid(), 'actual' => true, 'version' => $version + 1,
                'configurada_por' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['message' => 'Política guardada. Se aplica al preparar los requisitos; los existentes conservan su regla.']);
    }

    public function inicializar(Request $request, Legajo $legajo): JsonResponse
    {
        abort_unless($request->user()->can('vencimientos.gestionar'), 403);
        $data = $request->validate(['perfil_docente_id' => 'required|uuid', 'documento_id' => 'required|uuid', 'jefe_inmediato_id' => 'nullable|uuid|exists:usuarios,id']);
        $documento = DocumentoUsuario::query()->findOrFail($data['documento_id']);
        $this->exigirTipo($request, $documento->tipoDocumento, $legajo);
        $perfil = PerfilDocente::query()->findOrFail($data['perfil_docente_id']);
        if (! empty($data['jefe_inmediato_id'])) {
            abort_unless(Usuario::query()->whereKey($data['jefe_inmediato_id'])->where('activo', true)->exists(), 422, 'El jefe seleccionado debe tener cuenta activa.');
        }
        $ciclo = VigenciaLegajo::inicializar($legajo, $perfil, $documento, $request->user()->id, $data['jefe_inmediato_id'] ?? null);

        return response()->json(['data' => ['id' => $ciclo->id]], 201);
    }

    public function preparar(Request $request, CicloVigencia $ciclo): JsonResponse
    {
        $this->autorizarLegajo($request, $ciclo->legajo, true);
        DB::transaction(function () use ($ciclo, $request): void {
            $ciclo = VigenciaLegajo::bloquearActual($ciclo);
            VigenciaLegajo::asegurarRequisitos($ciclo);
            abort_if(! $ciclo->requerimientos()->exists(), 422, 'ORH debe configurar los documentos exigibles antes de presentar la renovación.');
            RenovacionLegajo::query()->firstOrCreate(['ciclo_id' => $ciclo->id]);
            VigenciaLegajo::auditar($ciclo, 'renovacion.preparar', $request->user()->id, []);
        });

        return response()->json(['message' => 'Renovación preparada.']);
    }

    public function subir(Request $request, CicloVigencia $ciclo, string $requisito): JsonResponse
    {
        $this->autorizarLegajo($request, $ciclo->legajo, true);
        $r = $ciclo->requerimientos()->findOrFail($requisito);
        $this->exigirTipo($request, $r->tipoDocumento, $ciclo->legajo);
        $data = $request->validate(['pdf' => ['required', 'file', 'max:'.Parametros::entero('tamano_maximo_pdf_kb')], 'fecha_vencimiento' => 'nullable|date_format:Y-m-d|after:'.VigenciaLegajo::hoy()->toDateString()]);
        abort_unless(ArchivoPdf::esPdf($request->file('pdf')), 422, 'El archivo no es un PDF válido.');
        DB::transaction(function () use ($ciclo, $r, $data, $request): void {
            $ciclo = VigenciaLegajo::bloquearActual($ciclo);
            $renovacion = RenovacionLegajo::query()->where('ciclo_id', $ciclo->id)->lockForUpdate()->first();
            abort_unless($renovacion && in_array($renovacion->estado, ['borrador', 'observada'], true), 409, 'Prepara la renovación o espera la revisión antes de modificarla.');
            $r->presentacion()->update(['actual' => false]);
            $pdf = $request->file('pdf');
            $ruta = $pdf->store('renovaciones/'.$ciclo->id, config('legajo.disco_documentos'));
            try {
                PresentacionRenovacion::query()->create(['requerimiento_id' => $r->id, 'storage_path' => $ruta, 'nombre_original' => mb_substr(basename($pdf->getClientOriginalName()), 0, 255),
                    'hash_sha256' => ArchivoPdf::hash($pdf), 'tamano_bytes' => $pdf->getSize(), 'subido_por' => $request->user()->id, 'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null]);
                $r->update(['estado' => 'abierto', 'documento_id' => null, 'fecha_vencimiento_evidencia' => null, 'cerrado_en' => null]);
                VigenciaLegajo::auditar($ciclo, 'renovacion.subir', $request->user()->id, ['requerimiento_id' => $r->id, 'hash' => ArchivoPdf::hash($pdf)]);
            } catch (\Throwable $exception) {
                Storage::disk(config('legajo.disco_documentos'))->delete($ruta);
                throw $exception;
            }
        });

        return response()->json(['message' => 'PDF presentado; falta su recepción e incorporación por ORH.'], 201);
    }

    public function archivo(Request $request, CicloVigencia $ciclo, string $presentacion): StreamedResponse
    {
        $this->autorizarLegajo($request, $ciclo->legajo);
        $p = PresentacionRenovacion::query()->whereIn('requerimiento_id', $ciclo->requerimientos()->select('id'))->findOrFail($presentacion);
        $r = $ciclo->requerimientos()->findOrFail($p->requerimiento_id);
        $this->exigirTipo($request, $r->tipoDocumento, $ciclo->legajo);
        $disco = Storage::disk(config('legajo.disco_documentos'));
        abort_unless($disco->exists($p->storage_path), 404);
        app(Antivirus::class)->exigir(config('legajo.disco_documentos'), $p->storage_path);
        BitacoraAccesoLegajo::query()->create(['legajo_id' => $ciclo->legajo_id, 'usuario_id' => $request->user()->id, 'accion' => 'descargar',
            'ip_origen' => $request->ip(), 'fecha_acceso' => now()]);

        return $disco->download($p->storage_path, $p->nombre_original, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function presentar(Request $request, CicloVigencia $ciclo): JsonResponse
    {
        $this->autorizarLegajo($request, $ciclo->legajo, true);
        DB::transaction(function () use ($ciclo, $request): void {
            $ciclo = VigenciaLegajo::bloquearActual($ciclo);
            $renovacion = RenovacionLegajo::query()->where('ciclo_id', $ciclo->id)->lockForUpdate()->first();
            abort_unless($renovacion && in_array($renovacion->estado, ['borrador', 'observada'], true), 409, 'La renovación ya está en revisión o debe prepararse.');
            $requisitos = $ciclo->requerimientos()->with('presentacion')->get();
            abort_if($requisitos->isEmpty() || $requisitos->contains(fn ($r) => ! $r->presentacion && ! $r->documento_id), 422, 'Presenta un PDF o evidencia incorporada para cada requisito.');
            $renovacion->update(['estado' => 'en_revision', 'presentada_en' => now(), 'observacion' => null]);
            VigenciaLegajo::auditar($ciclo, 'renovacion.presentar', $request->user()->id, []);
        });

        return response()->json(['message' => 'Renovación enviada a revisión.']);
    }

    public function vincular(Request $request, CicloVigencia $ciclo, string $requisito): JsonResponse
    {
        abort_unless($request->user()->can('renovaciones.aprobar'), 403);
        $data = $request->validate(['documento_id' => 'required|uuid', 'fecha_vencimiento' => 'nullable|date_format:Y-m-d|after:'.VigenciaLegajo::hoy()->toDateString()]);
        DB::transaction(function () use ($ciclo, $requisito, $data, $request): void {
            $ciclo = VigenciaLegajo::bloquearActual($ciclo);
            $r = $ciclo->requerimientos()->lockForUpdate()->findOrFail($requisito);
            $this->exigirTipo($request, $r->tipoDocumento, $ciclo->legajo);
            $documento = DocumentoUsuario::query()->lockForUpdate()->findOrFail($data['documento_id']);
            $r->fecha_vencimiento_evidencia = $r->presentacion?->fecha_vencimiento ?? ($data['fecha_vencimiento'] ?? null);
            VigenciaLegajo::validarCumplimiento($ciclo, $r, $documento, $request->user());
            $r->fill(['documento_id' => $documento->id, 'estado' => 'cumplido'])->save();
            RenovacionLegajo::query()->firstOrCreate(['ciclo_id' => $ciclo->id]);
            VigenciaLegajo::auditar($ciclo, 'renovacion.vincular', $request->user()->id, ['requerimiento_id' => $r->id, 'documento_id' => $documento->id]);
        });

        return response()->json(['message' => 'Evidencia aprobada vinculada al requisito.']);
    }

    public function revisar(Request $request, CicloVigencia $ciclo): JsonResponse
    {
        abort_unless($request->user()->can('renovaciones.aprobar'), 403);
        $data = $request->validate(['accion' => 'required|in:aprobar,observar', 'motivo' => 'required_if:accion,observar|nullable|string|max:2000']);
        if ($data['accion'] === 'aprobar') {
            foreach ($ciclo->requerimientos()->with('tipoDocumento')->get() as $r) {
                $this->exigirTipo($request, $r->tipoDocumento, $ciclo->legajo);
            }
            $nuevo = VigenciaLegajo::aprobar($ciclo, $request->user());

            return response()->json(['message' => 'Renovación aprobada.', 'data' => ['ciclo_id' => $nuevo->id, 'fecha_vencimiento' => $nuevo->fecha_vencimiento->toDateString()]]);
        }
        DB::transaction(function () use ($ciclo, $data, $request): void {
            $ciclo = VigenciaLegajo::bloquearActual($ciclo);
            $renovacion = RenovacionLegajo::query()->where('ciclo_id', $ciclo->id)->first();
            abort_unless($renovacion?->estado === 'en_revision', 409, 'Solo se observa una renovación presentada.');
            $renovacion->update(['estado' => 'observada', 'observacion' => trim($data['motivo'])]);
            VigenciaLegajo::auditar($ciclo, 'renovacion.observar', $request->user()->id, ['motivo' => $data['motivo']]);
        });

        return response()->json(['message' => 'Renovación observada. El titular puede subsanar.']);
    }

    public function fiscalizar(Request $request, CicloVigencia $ciclo, string $requisito): JsonResponse
    {
        abort_unless($request->user()->can('documento.fiscalizar') && $request->user()->can('renovaciones.aprobar'), 403);
        $data = $request->validate(['documento_id' => 'required|uuid', 'informe_documento_id' => 'required|uuid|different:documento_id',
            'fecha_inicio' => 'required|date_format:Y-m-d|before_or_equal:'.VigenciaLegajo::hoy()->toDateString(), 'conclusiones' => 'required|string|min:10|max:2000', 'conformidad_confirmada' => 'required|accepted']);
        DB::transaction(function () use ($ciclo, $requisito, $data, $request): void {
            $ciclo = VigenciaLegajo::bloquearActual($ciclo);
            $r = $ciclo->requerimientos()->lockForUpdate()->findOrFail($requisito);
            $this->exigirTipo($request, $r->tipoDocumento, $ciclo->legajo);
            $doc = DocumentoUsuario::query()->lockForUpdate()->findOrFail($data['documento_id']);
            abort_unless($doc->legajo_id === $ciclo->legajo_id && $doc->usuario_id === $ciclo->legajo->usuario_id && $doc->tipo_documento_id === $r->tipo_documento_id, 422, 'La evidencia no corresponde al requisito.');
            abort_unless($doc->tipoDocumento?->acredita_perfil_puesto && EstadoDocumento::recepcionado($doc), 422, 'La evidencia debe acreditar el perfil del puesto y tener recepción registrada.');
            abort_if($doc->fiscalizado || in_array($doc->estado, ['anulado', 'pendiente', 'observado', 'falso'], true), 409, 'La evidencia ya fue fiscalizada o no admite conformidad.');
            abort_if($doc->observacionesDocumentoPorDocumento()->where('estado', 'abierta')->exists(), 409, 'La evidencia tiene observaciones pendientes.');
            abort_if($doc->created_by === $request->user()->id || $doc->archivo?->subido_por === $request->user()->id, 403, 'Quien carga el PDF no puede fiscalizarlo.');
            $informe = DocumentoUsuario::query()->findOrFail($data['informe_documento_id']);
            $this->exigirTipo($request, $informe->tipoDocumento, $ciclo->legajo);
            abort_unless($informe->legajo_id === $ciclo->legajo_id && VigenciaLegajo::evidenciaAprobada($informe), 422, 'El informe de sustento debe estar incorporado y aprobado en el mismo legajo.');
            abort_if($doc->tipoDocumento->requiere_fedateo && ! $doc->fedateado, 422, 'La evidencia requiere fedateo previo.');
            $antes = $doc->getAttributes();
            $doc->update(['estado' => 'verificado', 'fiscalizado' => true, 'fecha_fiscalizacion' => VigenciaLegajo::hoy()->toDateString(), 'fiscalizado_por' => $request->user()->id]);
            VigenciaLegajo::validarCumplimiento($ciclo, $r, $doc, $request->user());
            $proceso = ProcesoFiscalizacion::query()->create(['usuario_id' => $doc->usuario_id, 'legajo_id' => $doc->legajo_id, 'tipo_muestra' => 'renovacion_legajo',
                'fecha_vinculacion' => $ciclo->perfilDocente->vinculoLaboral->fecha_inicio->toDateString(), 'fecha_inicio' => $data['fecha_inicio'],
                'fecha_limite' => VigenciaLegajo::hoy()->toDateString(), 'fecha_fin' => VigenciaLegajo::hoy()->toDateString(), 'estado' => 'cerrado',
                'responsable_id' => $request->user()->id, 'informe_documento_id' => $informe->id, 'conclusiones' => trim($data['conclusiones'])]);
            HistorialEstadoDocumento::query()->create(['documento_usuario_id' => $doc->id, 'estado_anterior' => $antes['estado'], 'estado_nuevo' => 'verificado',
                'cambiado_por' => $request->user()->id, 'motivo' => 'Fiscalización conforme. Informe '.$informe->id, 'fecha_cambio' => now(), 'ip_origen' => $request->ip()]);
            AuditoriaDocumento::registrar($doc, $request, 'renovacion.fiscalizar', $antes, ['proceso_id' => $proceso->id, 'informe_documento_id' => $informe->id, 'estado' => 'verificado']);
            VigenciaLegajo::auditar($ciclo, 'renovacion.fiscalizar', $request->user()->id, ['documento_id' => $doc->id, 'proceso_id' => $proceso->id]);
        });

        return response()->json(['message' => 'Fiscalización conforme registrada con informe de sustento. Ahora puedes vincular la evidencia.']);
    }

    public function notificar(Request $request, CicloVigencia $ciclo): JsonResponse
    {
        abort_unless($request->user()->can('vencimientos.notificar'), 403);
        $aviso = AvisosVencimiento::reintentar($ciclo, $request->user());

        return response()->json(['message' => 'Aviso pendiente de envío.', 'data' => ['id' => $aviso->id]], 202);
    }

    public function constancia(Request $request, Legajo $legajo): JsonResponse
    {
        $this->autorizarLegajo($request, $legajo);
        abort_unless($legajo->estado_legajo === 'activo', 409, 'El legajo ya no está activo.');
        $ciclo = $legajo->cicloActual;
        abort_unless($ciclo, 422, 'Falta registrar la fecha base acreditada del legajo.');
        abort_if(VigenciaLegajo::calcular($ciclo->categoria, $ciclo->fecha_base->toDateString(), $ciclo->anios)['estado_vigencia'] === 'vencido', 423, 'El legajo está vencido. Completa la renovación para emitir una constancia de vigencia.');

        return response()->json(['data' => ['numero_legajo' => $legajo->numero_legajo, 'vigente_hasta' => $ciclo->fecha_vencimiento->toDateString(), 'fecha_consulta' => VigenciaLegajo::hoy()->toDateString()]]);
    }

    private function fila(Legajo $legajo, Request $request): array
    {
        $ciclo = $legajo->cicloActual;
        $perfiles = $ciclo ? collect() : VigenciaLegajo::perfiles($legajo);
        $usuario = $legajo->usuario;

        return ['legajo_id' => $legajo->id, 'usuario_id' => $legajo->usuario_id, 'numero_legajo' => $legajo->numero_legajo,
            'nombre' => trim($usuario->nombres.' '.$usuario->apellido_paterno.' '.$usuario->apellido_materno), 'categoria' => $ciclo?->categoria ?? ($perfiles->count() === 1 ? $perfiles->first()->categoria : null),
            'ciclo_id' => $ciclo?->id, 'fecha_base' => $ciclo?->fecha_base->toDateString(), 'origen_base' => $ciclo?->origen_base,
            ...($ciclo ? VigenciaLegajo::calcular($ciclo->categoria, $ciclo->fecha_base->toDateString(), $ciclo->anios) : ['estado_vigencia' => 'revision', 'dias_restantes' => null, 'fecha_vencimiento' => null, 'color' => 'neutro']),
            'pendientes' => $ciclo ? $ciclo->requerimientos->where('estado', '!=', 'cumplido')->count() : null,
            'estado_renovacion' => $ciclo?->renovacion?->estado, 'mensaje_datos' => $ciclo ? null : ($perfiles->isEmpty() ? 'No hay perfil ordinario activo aplicable; registra o revisa el vínculo y la categoría.' : 'Selecciona el perfil y una resolución aprobada para iniciar la vigencia.')];
    }

    private function autorizarPanel(Request $request): void
    {
        abort_unless($request->user()->can('vencimientos.ver') || $request->user()->can('renovacion.propia'), 403);
    }

    private function autorizarLegajo(Request $request, Legajo $legajo, bool $escritura = false): void
    {
        $permiso = $escritura ? 'renovaciones.gestionar' : 'vencimientos.ver';
        abort_unless($request->user()->can($permiso) || ($request->user()->can('renovacion.propia') && $legajo->usuario_id === $request->user()->id), 403);
    }

    private function puedeTipo(Request $request, ?TipoDocumento $tipo, ?Legajo $legajo = null): bool
    {
        if (! $tipo) {
            return false;
        }
        $sensible = in_array($tipo->seccion_legajo_id, [1, 9, 10], true) || $tipo->es_dato_sensible || $tipo->es_dato_salud;
        $propio = $legajo && $request->user()->can('renovacion.propia') && $legajo->usuario_id === $request->user()->id;

        return (! $sensible || $propio || $request->user()->veDatosSensibles())
            && ($tipo->seccion_legajo_id !== 8 || ! in_array($tipo->codigo, ['SAN_RESOLUCION_SANCION', 'SAN_RESOLUCION_RECURSO'], true) || $propio || $request->user()->can('seccion08.sanciones.ver'));
    }

    private function exigirTipo(Request $request, ?TipoDocumento $tipo, ?Legajo $legajo = null): void
    {
        abort_unless($tipo && $tipo->activo && $tipo->seccionLegajo?->activa, 422, 'El tipo o su sección están deshabilitados. ORH debe resolver la configuración.');
        abort_if($tipo->seccion_legajo_id === 9 && $tipo->codigo !== 'S09_C_AFILIACION_SINDICATO', 403, 'Solo está habilitada la afiliación sindical.');
        abort_unless($this->puedeTipo($request, $tipo, $legajo), 403, 'No tienes acceso a este requisito documental.');
    }
}
