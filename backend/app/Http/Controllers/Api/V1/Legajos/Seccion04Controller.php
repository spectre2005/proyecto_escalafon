<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\DocumentoUsuario;
use App\Models\ExperienciaLaboral;
use App\Models\Legajo;
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
use App\Support\ResumenSeccion04;
use App\Support\TiempoExperiencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Seccion04Controller extends Controller
{
    public function show(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->with('vinculosLaborales')->findOrFail($usuario);
        $legajo = Legajo::paraConsulta($usuario);
        $perfil = PerfilDocumental::de($persona, $persona->vinculosLaborales);
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 4)->where('activo', true)->whereIn('codigo', array_keys(ResumenSeccion04::ITEMS));
        if (! $request->user()->veDatosSensibles()) {
            $tipos->where('es_dato_sensible', false)->where('es_dato_salud', false);
        }
        $docs = $legajo ? DocumentoUsuario::query()->where('legajo_id', $legajo->id)->where('seccion_legajo_id', 4)
            ->with(['archivo', 'experienciaLaboral', 'observacionesDocumentoPorDocumento', 'documentosUsuarioPorSubsanaADocumento'])
            ->orderByDesc('fecha_emision')->orderByDesc('id')->get() : collect();
        $items = $tipos->orderBy('orden')->get()->map(function (TipoDocumento $tipo) use ($perfil, $docs, $request): array {
            $exigencia = $perfil->exigencia($tipo);
            $documentos = $docs->where('tipo_documento_id', $tipo->id)->map(fn (DocumentoUsuario $doc): array => $this->documento($doc, $request))->values();

            return [...$tipo->only(['id', 'codigo', 'nombre', 'permite_multiples', 'requiere_fedateo', 'requiere_num_resolucion']),
                'letra' => ResumenSeccion04::ITEMS[$tipo->codigo][0], 'grupo' => 0, ...$exigencia,
                'estado' => ResumenSeccion03::estado($exigencia['exigido'], ! $tipo->obligatorio, $documentos), 'aplica' => true,
                'cantidad' => $documentos->filter(fn (array $d): bool => ! $d['subsanado'] && $d['estado'] !== 'anulado')->count(), 'documentos' => $documentos->all()];
        });
        $tiempo = TiempoExperiencia::paraPersona($usuario, permitirSensibles: $request->user()->veDatosSensibles());

        return response()->json(['data' => [
            'persona' => $persona->only(['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'tipo_personal', 'nombre_puesto']),
            'perfil' => ['tipos_personal' => $perfil->tiposPersonal, 'puestos' => $perfil->puestos],
            'legajo' => $legajo?->only(['id', 'numero_legajo', 'estado_legajo']), 'items' => $items->all(), 'resumen' => ResumenSeccion04::total($items),
            'numero_folios' => $legajo ? Folios::ultimo($legajo, 4) : 0, 'folio_sugerido' => $legajo ? Folios::ultimo($legajo, 4) + 1 : null,
            'tamano_maximo_kb' => Parametros::entero('tamano_maximo_pdf_kb'), 'tiempo' => $tiempo,
        ]]);
    }

    public function store(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $legajo = $this->legajo($persona);
        abort_unless($legajo, 409, 'Primero abre el legajo para agregar documentos.');
        $request->validate(['tipo_documento_id' => ['required', 'uuid', Rule::exists('tipos_documento', 'id')->where('seccion_legajo_id', 4)->where('activo', true)]]);
        $tipo = TipoDocumento::query()->findOrFail($request->input('tipo_documento_id'));
        abort_unless(isset(ResumenSeccion04::ITEMS[$tipo->codigo]), 422, 'Selecciona un tipo del catálogo de experiencia laboral.');
        abort_if(($tipo->es_dato_sensible || $tipo->es_dato_salud) && ! $request->user()->veDatosSensibles(), 403, 'Tu perfil no tiene acceso a este documento sensible.');
        $hoy = now('America/Lima')->toDateString();
        $reglas = [
            'tipo_documento_id' => 'required|uuid', 'nombre' => 'required|string|max:200', 'emisor' => 'required|string|max:200',
            'fecha_emision' => 'required|date_format:Y-m-d|before_or_equal:'.$hoy, 'folio_inicio' => 'required|integer|min:1',
            'folio_fin' => 'required|integer|gte:folio_inicio', 'modalidad_presentacion' => ['required', Rule::in(DocumentoLegajoController::MODALIDADES)],
            'pdf' => ['required', 'file', 'max:'.Parametros::entero('tamano_maximo_pdf_kb')], 'subsana_a_documento_id' => 'nullable|uuid',
        ];
        if ($tipo->codigo === 'EXP_CERTIFICADO_TRABAJO') {
            $reglas += ['sector' => 'required|in:publico,privado', 'cargo' => 'required|string|max:150',
                'fecha_inicio' => 'required|date_format:Y-m-d|before_or_equal:'.$hoy,
                'fecha_fin' => 'nullable|date_format:Y-m-d|after_or_equal:fecha_inicio|before_or_equal:'.$hoy,
                'tipo_vinculo' => 'required|in:planilla,contrato_civil,otro',
                'es_ejercicio_profesional' => 'required|boolean', 'es_docencia_universitaria' => 'required|boolean'];
        }
        if ($tipo->codigo === 'EXP_RESOLUCION_FUNCIONES') {
            $reglas += ['funcion_asignada' => 'required|string|max:3000'];
        }
        if ($tipo->requiere_num_resolucion) {
            $reglas['num_resolucion'] = 'required|string|max:60';
        }
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }
        $datos = $request->validate($reglas, [
            'fecha_fin.after_or_equal' => 'El fin del servicio no puede ser anterior al inicio.',
            'fecha_inicio.before_or_equal' => 'La fecha de inicio no puede ser futura.',
            'fecha_fin.before_or_equal' => 'La fecha de fin no puede ser futura. Marca Hasta la fecha si continúa.',
            'folio_fin.gte' => 'El folio final debe ser igual o mayor que el inicial.',
            'pdf.max' => 'El PDF supera el tamaño permitido. Reduce su tamaño e intenta nuevamente.',
        ]);
        if (! ArchivoPdf::esPdf($request->file('pdf'))) {
            throw ValidationException::withMessages(['pdf' => 'Selecciona un archivo que contenga un PDF válido.']);
        }
        foreach (['nombre', 'emisor', 'cargo', 'funcion_asignada', 'num_resolucion'] as $campo) {
            if (isset($datos[$campo])) {
                $datos[$campo] = trim($datos[$campo]);
                if ($datos[$campo] === '') {
                    throw ValidationException::withMessages([$campo => 'Completa este dato antes de guardar.']);
                }
            }
        }
        $meta = collect($datos)->only(['nombre', 'emisor', 'fecha_emision', 'folio_inicio', 'folio_fin', 'modalidad_presentacion', 'num_resolucion', 'funcion_asignada'])->all();
        $meta += ['fecha_incorporacion' => $hoy, 'titular_nombre' => mb_substr(trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 0, 200),
            'titular_documento' => $persona->numero_doc_identidad, 'relacion_titular' => 'servidor'];
        $doc = CargaDocumento::registrar($legajo, $tipo, $request->file('pdf'), $meta, $request->user()->getAuthIdentifier(), $request->ip(), $datos['subsana_a_documento_id'] ?? null,
            estadoInicial: 'pendiente', completar: function (DocumentoUsuario $doc) use ($datos, $tipo, $request): void {
                abort_unless($doc->legajo->estado_legajo === 'activo', 409, 'El legajo se cerró durante la carga. Actualiza la ficha.');
                if ($tipo->codigo === 'EXP_CERTIFICADO_TRABAJO') {
                    ExperienciaLaboral::query()->create([
                        'usuario_id' => $doc->usuario_id, 'documento_id' => $doc->id, 'registrado_por' => $doc->created_by,
                        'entidad_empresa' => $doc->emisor, ...collect($datos)->only(['sector', 'cargo', 'fecha_inicio', 'fecha_fin', 'tipo_vinculo', 'es_ejercicio_profesional', 'es_docencia_universitaria'])->all(),
                    ]);
                }
                AuditoriaDocumento::registrar($doc, $request, 'experiencia.documento.creado');
            });

        return response()->json(['data' => $doc->only(['id', 'nombre', 'estado', 'folio_inicio', 'folio_fin'])], 201);
    }

    public function cambiarEstado(Request $request, string $usuario, string $documento): JsonResponse
    {
        return RevisionDocumento::cambiarEstado($request, $usuario, $documento, 4, 'experiencia.documento');
    }

    private function documento(DocumentoUsuario $doc, Request $request): array
    {
        return [...$doc->only(['id', 'nombre', 'emisor', 'num_resolucion', 'funcion_asignada', 'folio_inicio', 'folio_fin', 'fedateado', 'fiscalizado', 'modalidad_presentacion', 'subsana_a_documento_id']),
            'nombre' => $doc->nombre ?: ($doc->archivo?->nombre_original ?? 'Documento sin archivo'),
            'fecha_emision' => $doc->fecha_emision?->format('Y-m-d'), 'fecha_recepcion' => $doc->fecha_recepcion?->format('Y-m-d'),
            'estado' => ResumenSeccion03::estadoDocumento($doc->estado), 'estado_original' => $doc->estado,
            'subsanado' => $doc->documentosUsuarioPorSubsanaADocumento->isNotEmpty(),
            'puede_revisar' => $doc->created_by !== $request->user()->getAuthIdentifier() && $doc->estado !== 'anulado' && $doc->documentosUsuarioPorSubsanaADocumento->isEmpty(),
            'archivo' => $doc->archivo?->only(['id', 'nombre_original', 'mime_type', 'estado_antivirus', 'tamano_bytes']),
            'experiencias' => $doc->experienciaLaboral->map(fn (ExperienciaLaboral $p): array => [...$p->only(['id', 'entidad_empresa', 'sector', 'cargo', 'tipo_vinculo', 'es_ejercicio_profesional', 'es_docencia_universitaria']),
                'fecha_inicio' => $p->fecha_inicio->format('Y-m-d'), 'fecha_fin' => $p->fecha_fin?->format('Y-m-d')])->all(),
            'observaciones' => $doc->observacionesDocumentoPorDocumento->where('estado', 'abierta')->map(fn ($o): array => ['descripcion' => $o->descripcion, 'plazo_subsanacion' => $o->plazo_subsanacion?->format('Y-m-d')])->values()->all(),
        ];
    }

    private function legajo(Usuario $persona): ?Legajo
    {
        return $persona->legajos()->where('estado_legajo', 'activo')->orderByDesc('fecha_apertura')->orderByDesc('id')->first();
    }
}
