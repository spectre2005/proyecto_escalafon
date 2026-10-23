<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\CompensacionBeneficio;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TiempoServicioReconocido;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\ArchivoPdf;
use App\Support\Documentos\AuditoriaDocumento;
use App\Support\Documentos\CargaDocumento;
use App\Support\Documentos\Folios;
use App\Support\Documentos\RevisionDocumento;
use App\Support\Parametros;
use App\Support\PerfilDocumental;
use App\Support\ResumenSeccion03;
use App\Support\ResumenSeccion06;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Seccion06Controller extends Controller
{
    public function show(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->with('vinculosLaborales')->findOrFail($usuario);
        $legajo = Legajo::paraConsulta($usuario);
        $perfil = PerfilDocumental::de($persona, $persona->vinculosLaborales);
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 6)->where('activo', true)->whereIn('codigo', array_keys(ResumenSeccion06::ITEMS));
        if (! $request->user()->veDatosSensibles()) {
            $tipos->where('es_dato_sensible', false)->where('es_dato_salud', false);
        }
        $docs = $legajo ? DocumentoUsuario::query()->where('legajo_id', $legajo->id)->where('seccion_legajo_id', 6)
            ->with(['archivo', 'tiempoServiciosReconocidos', 'compensacionesBeneficios', 'observacionesDocumentoPorDocumento', 'documentosUsuarioPorSubsanaADocumento'])
            ->orderByDesc('fecha_emision')->orderByDesc('id')->get() : collect();
        $items = $tipos->orderBy('orden')->get()->map(function (TipoDocumento $tipo) use ($perfil, $docs, $request): array {
            $exigencia = $perfil->exigencia($tipo);
            $documentos = $docs->where('tipo_documento_id', $tipo->id)->map(fn (DocumentoUsuario $doc): array => $this->documento($doc, $request))->values();

            return [...$tipo->only(['id', 'codigo', 'nombre', 'permite_multiples', 'requiere_fedateo', 'requiere_num_resolucion', 'descripcion', 'es_hook_universidad']),
                'letra' => ResumenSeccion06::ITEMS[$tipo->codigo][0], 'grupo' => 0, ...$exigencia,
                'estado' => ResumenSeccion03::estado($exigencia['exigido'], ! $tipo->obligatorio, $documentos), 'aplica' => true,
                'cantidad' => $documentos->filter(fn (array $d): bool => ! $d['subsanado'] && $d['estado'] !== 'anulado')->count(), 'documentos' => $documentos->all()];
        });

        return response()->json(['data' => [
            'persona' => $persona->only(['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'tipo_personal', 'nombre_puesto', 'numero_doc_identidad']),
            'perfil' => ['tipos_personal' => $perfil->tiposPersonal, 'puestos' => $perfil->puestos],
            'legajo' => $legajo?->only(['id', 'numero_legajo', 'estado_legajo']), 'items' => $items->all(), 'resumen' => ResumenSeccion06::total($items),
            'numero_folios' => $legajo ? Folios::ultimo($legajo, 6) : 0, 'folio_sugerido' => $legajo ? Folios::ultimo($legajo, 6) + 1 : null,
            'fecha_actual' => now('America/Lima')->toDateString(), 'tamano_maximo_kb' => Parametros::entero('tamano_maximo_pdf_kb'),
        ]]);
    }

    public function store(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $legajo = $this->legajo($persona);
        abort_unless($legajo, 409, 'Primero abre el legajo para agregar documentos.');
        $request->validate(['tipo_documento_id' => ['required', 'uuid', Rule::exists('tipos_documento', 'id')->where('seccion_legajo_id', 6)->where('activo', true)]]);
        $tipo = TipoDocumento::query()->findOrFail($request->input('tipo_documento_id'));
        abort_unless(isset(ResumenSeccion06::ITEMS[$tipo->codigo]), 422, 'Selecciona un tipo del catálogo de compensaciones.');
        abort_if(($tipo->es_dato_sensible || $tipo->es_dato_salud) && ! $request->user()->veDatosSensibles(), 403, 'Tu perfil no tiene acceso a este documento sensible.');
        $hoy = now('America/Lima')->toDateString();
        $reglas = [
            'tipo_documento_id' => 'required|uuid', 'nombre' => 'required|string|max:200', 'emisor' => 'required|string|max:200',
            'fecha_emision' => 'required|date_format:Y-m-d|before_or_equal:'.$hoy, 'folio_inicio' => 'required|integer|between:1,2147483647',
            'folio_fin' => 'required|integer|between:1,2147483647|gte:folio_inicio', 'modalidad_presentacion' => ['required', Rule::in(DocumentoLegajoController::MODALIDADES)],
            'pdf' => ['required', 'file', 'max:'.Parametros::entero('tamano_maximo_pdf_kb')], 'subsana_a_documento_id' => 'nullable|uuid',
        ];
        if ($tipo->codigo === 'COMP_RECONOCIMIENTO_TIEMPO') {
            $reglas += ['anios' => 'required|integer|between:0,2147483647', 'meses' => 'required|integer|between:0,2147483647', 'dias' => 'required|integer|between:0,2147483647'];
        }
        if ($tipo->codigo === 'COMP_PAGO_COMPENSACION') {
            $reglas += ['tipo' => 'required|in:economica,no_economica', 'concepto' => 'required|string|max:200',
                'fecha' => 'required|date_format:Y-m-d|before_or_equal:'.$hoy, 'numero_documento' => 'nullable|string|max:60'];
        }
        if ($tipo->requiere_num_resolucion || $tipo->codigo === 'COMP_RECONOCIMIENTO_TIEMPO') {
            $reglas['num_resolucion'] = 'required|string|max:60';
        }
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }
        $datos = $request->validate($reglas, [
            'folio_fin.gte' => 'El folio final debe ser igual o mayor que el inicial.',
            'pdf.max' => 'El PDF supera el tamaño permitido. Reduce su tamaño e intenta nuevamente.',
        ]);
        if (! ArchivoPdf::esPdf($request->file('pdf'))) {
            throw ValidationException::withMessages(['pdf' => 'Selecciona un archivo que contenga un PDF válido.']);
        }
        foreach (['nombre', 'emisor', 'concepto', 'num_resolucion', 'numero_documento'] as $campo) {
            if (isset($datos[$campo])) {
                $datos[$campo] = trim($datos[$campo]);
                if ($datos[$campo] === '') {
                    throw ValidationException::withMessages([$campo => 'Completa este dato antes de guardar.']);
                }
            }
        }
        $meta = collect($datos)->only(['nombre', 'emisor', 'fecha_emision', 'folio_inicio', 'folio_fin', 'modalidad_presentacion', 'num_resolucion'])->all();
        $meta += ['fecha_incorporacion' => $hoy, 'titular_nombre' => mb_substr(trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 0, 200),
            'titular_documento' => $persona->numero_doc_identidad, 'relacion_titular' => 'servidor'];
        $doc = CargaDocumento::registrar($legajo, $tipo, $request->file('pdf'), $meta, $request->user()->getAuthIdentifier(), $request->ip(), $datos['subsana_a_documento_id'] ?? null,
            completar: function (DocumentoUsuario $doc) use ($datos, $tipo, $request): void {
                abort_unless($doc->legajo->estado_legajo === 'activo', 409, 'El legajo se cerró durante la carga. Actualiza la ficha.');
                if ($tipo->codigo === 'COMP_RECONOCIMIENTO_TIEMPO') {
                    TiempoServicioReconocido::query()->create([
                        'usuario_id' => $doc->usuario_id, 'documento_id' => $doc->id, 'registrado_por' => $doc->created_by,
                        'numero_resolucion' => $doc->num_resolucion, 'fecha_resolucion' => $doc->fecha_emision,
                        ...collect($datos)->only(['anios', 'meses', 'dias'])->all(),
                    ]);
                }
                if ($tipo->codigo === 'COMP_PAGO_COMPENSACION') {
                    CompensacionBeneficio::query()->create([
                        'usuario_id' => $doc->usuario_id, 'documento_id' => $doc->id, 'registrado_por' => $doc->created_by,
                        ...collect($datos)->only(['tipo', 'concepto', 'fecha', 'numero_documento'])->all(),
                    ]);
                }
                AuditoriaDocumento::registrar($doc, $request, 'compensaciones.documento.creado');
            });

        return response()->json(['data' => $doc->only(['id', 'nombre', 'estado', 'folio_inicio', 'folio_fin'])], 201);
    }

    public function cambiarEstado(Request $request, string $usuario, string $documento): JsonResponse
    {
        return RevisionDocumento::cambiarEstado($request, $usuario, $documento, 6, 'compensaciones.documento');
    }

    private function documento(DocumentoUsuario $doc, Request $request): array
    {
        return [...$doc->only(['id', 'nombre', 'emisor', 'num_resolucion', 'folio_inicio', 'folio_fin', 'fedateado', 'fiscalizado', 'resultado_fiscalizacion', 'proceso_fiscalizacion_id', 'modalidad_presentacion', 'subsana_a_documento_id']),
            'nombre' => $doc->nombre ?: ($doc->archivo?->nombre_original ?? 'Documento sin archivo'),
            'fecha_emision' => $doc->fecha_emision?->format('Y-m-d'), 'fecha_incorporacion' => $doc->fecha_incorporacion?->format('Y-m-d'),
            'titular_nombre' => $doc->titular_nombre, 'titular_documento' => $doc->titular_documento, 'fecha_recepcion' => $doc->fecha_recepcion?->format('Y-m-d'),
            'estado' => ResumenSeccion03::estadoDocumento($doc->estado), 'estado_original' => $doc->estado,
            'subsanado' => $doc->documentosUsuarioPorSubsanaADocumento->isNotEmpty(),
            'puede_revisar' => $doc->created_by !== $request->user()->getAuthIdentifier() && $doc->estado !== 'anulado' && $doc->documentosUsuarioPorSubsanaADocumento->isEmpty(),
            'archivo' => $doc->archivo?->only(['id', 'nombre_original', 'mime_type', 'estado_antivirus', 'tamano_bytes']),
            'tiempos_reconocidos' => $doc->tiempoServiciosReconocidos->where('usuario_id', $doc->usuario_id)->map(fn (TiempoServicioReconocido $p): array => [...$p->only(['id', 'numero_resolucion', 'anios', 'meses', 'dias', 'anulado']), 'fecha_resolucion' => $p->fecha_resolucion->format('Y-m-d')])->all(),
            'compensaciones' => $doc->compensacionesBeneficios->where('usuario_id', $doc->usuario_id)->map(fn (CompensacionBeneficio $p): array => [...$p->only(['id', 'tipo', 'concepto', 'numero_documento', 'anulado']), 'fecha' => $p->fecha->format('Y-m-d')])->all(),
            'observaciones' => $doc->observacionesDocumentoPorDocumento->where('estado', 'abierta')->map(fn ($o): array => ['descripcion' => $o->descripcion, 'plazo_subsanacion' => $o->plazo_subsanacion?->format('Y-m-d')])->values()->all(),
        ];
    }

    private function legajo(Usuario $persona): ?Legajo
    {
        return $persona->legajos()->where('estado_legajo', 'activo')->orderByDesc('fecha_apertura')->orderByDesc('id')->first();
    }
}
