<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\Capacitacion;
use App\Models\Colegiatura;
use App\Models\DocumentoUsuario;
use App\Models\FormacionAcademica;
use App\Models\HistorialEstadoDocumento;
use App\Models\Legajo;
use App\Models\ObservacionDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\ArchivoPdf;
use App\Support\Documentos\AuditoriaDocumento;
use App\Support\Documentos\CargaDocumento;
use App\Support\Documentos\Folios;
use App\Support\Parametros;
use App\Support\PerfilDocumental;
use App\Support\ResumenSeccion03;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Seccion03Controller extends Controller
{
    public function show(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->with('vinculosLaborales')->findOrFail($usuario);
        $legajo = $this->legajo($persona);
        $perfil = PerfilDocumental::de($persona, $persona->vinculosLaborales);
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 3)->where('activo', true)
            ->whereIn('codigo', array_keys(ResumenSeccion03::ITEMS));
        if (! $request->user()->veDatosSensibles()) {
            $tipos->where('es_dato_sensible', false)->where('es_dato_salud', false);
        }
        $documentos = $legajo ? DocumentoUsuario::query()->where('legajo_id', $legajo->id)->where('seccion_legajo_id', 3)
            ->with(['archivo', 'observacionesDocumentoPorDocumento', 'documentosUsuarioPorSubsanaADocumento'])->orderByDesc('fecha_emision')->orderByDesc('id')->get() : collect();
        $items = $tipos->orderBy('orden')->get()->map(function (TipoDocumento $tipo) use ($perfil, $documentos, $request): array {
            $exigencia = $perfil->exigencia($tipo);
            if ($exigencia['exigido'] === true) {
                $exigencia['motivo'] = match (true) {
                    $tipo->requiere_docente => 'Se exige al personal docente.',
                    $tipo->requiere_colegiatura => 'El puesto exige colegiatura y habilitación profesional.',
                    $tipo->requiere_estudios_basicos => 'El perfil del puesto exige estudios básicos.',
                    default => 'Se exige para acreditar la formación del puesto. Se verifica con SUNEDU o MINEDU en la fiscalización posterior.',
                };
            }
            $lista = $documentos->where('tipo_documento_id', $tipo->id)->map(fn (DocumentoUsuario $doc): array => $this->documento($doc, $request))->values();
            [$letra, $grupo] = ResumenSeccion03::ITEMS[$tipo->codigo];
            $opcional = ! $tipo->obligatorio || ($tipo->requiere_docente && $exigencia['exigido'] === false);
            $aplica = ! (($tipo->requiere_estudios_basicos || $tipo->requiere_colegiatura) && $exigencia['exigido'] === false);

            return [...$tipo->only(['id', 'codigo', 'nombre', 'permite_multiples', 'requiere_fedateo']),
                'letra' => $letra, 'grupo' => $grupo, ...$exigencia,
                'estado' => ResumenSeccion03::estado($exigencia['exigido'], $opcional, $lista),
                'aplica' => $aplica, 'documentos' => $lista->all(),
                'cantidad' => $lista->filter(fn (array $doc): bool => ! $doc['subsanado'] && $doc['estado'] !== 'anulado')->count(),
            ];
        });

        return response()->json(['data' => [
            'persona' => $persona->only(['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'tipo_personal', 'nombre_puesto']),
            'perfil' => ['tipos_personal' => $perfil->tiposPersonal, 'puestos' => $perfil->puestos],
            'legajo' => $legajo?->only(['id', 'numero_legajo']), 'items' => $items->all(), 'resumen' => ResumenSeccion03::total($items),
            'folio_sugerido' => $legajo ? Folios::ultimo($legajo, 3) + 1 : null,
            'tamano_maximo_kb' => Parametros::entero('tamano_maximo_pdf_kb'),
        ]]);
    }

    public function store(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->with('vinculosLaborales')->findOrFail($usuario);
        $legajo = $this->legajo($persona);
        abort_unless($legajo, 409, 'Primero abre el legajo de esta persona para agregar documentos.');
        $request->validate(['tipo_documento_id' => ['required', 'uuid', Rule::exists('tipos_documento', 'id')->where('seccion_legajo_id', 3)->where('activo', true)]]);
        $tipo = TipoDocumento::query()->findOrFail($request->input('tipo_documento_id'));
        abort_unless(isset(ResumenSeccion03::ITEMS[$tipo->codigo]), 422, 'El tipo no pertenece al catálogo de formación académica.');
        $this->exigirSensibles($request, $tipo);
        $exigencia = PerfilDocumental::de($persona, $persona->vinculosLaborales)->exigencia($tipo);
        if (($tipo->requiere_estudios_basicos || $tipo->requiere_colegiatura) && $exigencia['exigido'] === false) {
            throw ValidationException::withMessages(['tipo_documento_id' => 'Este documento no aplica al puesto registrado. Revisa sus indicadores en la ficha o el vínculo laboral.']);
        }
        [$letra] = ResumenSeccion03::ITEMS[$tipo->codigo];
        $reglas = [
            'tipo_documento_id' => 'required|uuid', 'nombre' => 'required|string|max:200',
            'emisor' => 'required|string|max:200', 'fecha_emision' => 'required|date_format:Y-m-d|before_or_equal:today',
            'folio_inicio' => 'required|integer|min:1', 'folio_fin' => 'required|integer|gte:folio_inicio',
            'modalidad_presentacion' => ['required', Rule::in(DocumentoLegajoController::MODALIDADES)],
            'pdf' => ['required', 'file', 'max:'.Parametros::entero('tamano_maximo_pdf_kb')],
            'subsana_a_documento_id' => 'nullable|uuid',
        ];
        if (in_array($letra, ['b', 'e'], true)) {
            $reglas['numero_registro'] = 'nullable|string|max:40';
            $reglas['nivel'] = ['required', Rule::in($letra === 'b' ? ['tecnico', 'bachiller', 'titulo_profesional'] : ['maestria', 'doctorado'])];
        }
        if ($letra === 'c') {
            $reglas['numero_colegiatura'] = 'required|string|max:30';
            $reglas['vigente_hasta'] = 'nullable|date_format:Y-m-d|after_or_equal:fecha_emision';
        }
        if (in_array($letra, ['f', 'g'], true)) {
            $reglas['tipo_capacitacion'] = ['required', Rule::in($letra === 'f' ? ['curso', 'programa_especializacion'] : ['congreso', 'seminario', 'taller'])];
            $reglas['horas'] = 'nullable|integer|min:1|max:100000';
        }
        $this->rechazarDesconocidos($request, $reglas);
        $datos = $request->validate($reglas, [
            'folio_fin.gte' => 'El folio final debe ser igual o mayor que el inicial.',
            'pdf.max' => 'El PDF supera el tamaño permitido. Reduce su tamaño e intenta nuevamente.',
            'nivel.required' => 'Selecciona el nivel académico que acredita el documento.',
            'numero_colegiatura.required' => 'Ingresa el número de colegiatura.',
        ]);
        if (! ArchivoPdf::esPdf($request->file('pdf'))) {
            throw ValidationException::withMessages(['pdf' => 'El archivo no contiene un PDF válido. Selecciona un PDF e intenta nuevamente.']);
        }
        $metadatos = collect($datos)->only(['nombre', 'emisor', 'fecha_emision', 'folio_inicio', 'folio_fin', 'modalidad_presentacion'])->map(fn ($valor) => is_string($valor) ? trim($valor) : $valor)->all();
        $metadatos = [...$metadatos,
            'num_resolucion' => $datos['numero_registro'] ?? null, 'fecha_incorporacion' => today()->toDateString(),
            'titular_nombre' => mb_substr(trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 0, 200),
            'titular_documento' => $persona->numero_doc_identidad, 'relacion_titular' => 'servidor',
        ];
        $documento = CargaDocumento::registrar($legajo, $tipo, $request->file('pdf'), $metadatos, $request->user()->getAuthIdentifier(), $request->ip(),
            $datos['subsana_a_documento_id'] ?? null, estadoInicial: 'pendiente', completar: function (DocumentoUsuario $doc) use ($datos, $letra, $request): void {
                abort_unless($doc->legajo->estado_legajo === 'activo', 409, 'El legajo se cerró durante la carga. Actualiza la ficha.');
                $this->registrarDatos($doc, $datos, $letra);
                AuditoriaDocumento::registrar($doc, $request, 'formacion.documento.creado');
            });

        return response()->json(['data' => $documento->only(['id', 'nombre', 'estado', 'folio_inicio', 'folio_fin'])], 201);
    }

    public function cambiarEstado(Request $request, string $usuario, string $documento): JsonResponse
    {
        $reglas = [
            'estado' => 'required|in:aceptado,observado', 'estado_anterior' => 'required|string|max:20',
            'descripcion' => 'required_if:estado,observado|nullable|string|max:3000',
            'plazo_subsanacion' => 'required_if:estado,observado|nullable|date_format:Y-m-d|after_or_equal:today',
            'recepcion_confirmada' => 'required_if:estado,aceptado|boolean',
            'fedateado' => 'sometimes|boolean',
        ];
        $this->rechazarDesconocidos($request, $reglas);
        $datos = $request->validate($reglas);
        $registro = DB::transaction(function () use ($request, $usuario, $documento, $datos): DocumentoUsuario {
            $legajo = Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->lockForUpdate()->first();
            abort_unless($legajo, 409, 'El legajo está cerrado y no admite revisiones.');
            $doc = DocumentoUsuario::query()->where('usuario_id', $usuario)->where('legajo_id', $legajo->id)->where('seccion_legajo_id', 3)->with(['tipoDocumento', 'archivo'])
                ->lockForUpdate()->findOrFail($documento);
            $this->exigirSensibles($request, $doc->tipoDocumento);
            abort_unless($doc->legajo->estado_legajo === 'activo', 409, 'El legajo está cerrado y no admite revisiones.');
            abort_if($doc->created_by === $request->user()->getAuthIdentifier(), 403, 'La revisión debe hacerla otra persona de RR. HH. No puedes revisar tu propia carga.');
            abort_if($doc->documentosUsuarioPorSubsanaADocumento()->exists() || $doc->estado === 'anulado', 409, 'El documento fue subsanado o anulado. Revisa el documento vigente.');
            abort_if($doc->estado !== $datos['estado_anterior'] || $doc->estado === $datos['estado'], 409, 'El documento cambió desde que lo abriste. Actualiza la lista antes de revisarlo.');
            $antes = $doc->getAttributes();
            $cambios = ['estado' => $datos['estado']];
            $observacion = null;
            if ($datos['estado'] === 'aceptado') {
                if (! ($datos['recepcion_confirmada'] ?? false)) {
                    throw ValidationException::withMessages(['recepcion_confirmada' => 'Confirma que verificaste la recepción, el sello y los folios.']);
                }
                if (! $doc->archivo || $doc->archivo->estado_antivirus === 'infectado') {
                    throw ValidationException::withMessages(['estado' => 'El documento no tiene un archivo disponible o el archivo está bloqueado. Registra una subsanación.']);
                }
                if ($doc->tipoDocumento->requiere_fedateo && ! $doc->fedateado && ! ($datos['fedateado'] ?? false)) {
                    throw ValidationException::withMessages(['fedateado' => 'Este título requiere fedateo. Confirma el fedateo antes de aceptarlo.']);
                }
                if (! $doc->fecha_recepcion) {
                    $cambios += ['fecha_recepcion' => today(), 'recepcionado_por' => $request->user()->getAuthIdentifier()];
                }
                if (($datos['fedateado'] ?? false) && ! $doc->fedateado) {
                    $cambios += ['fedateado' => true, 'fecha_fedateo' => today(), 'fedatario_id' => $request->user()->getAuthIdentifier()];
                }
                abort_if($doc->observacionesDocumentoPorDocumento()->where('estado', 'abierta')->exists(), 409, 'Tiene una observación abierta. Registra un documento nuevo que la subsane antes de aceptarlo.');
            } else {
                $observacion = ObservacionDocumento::query()->create([
                    'documento_id' => $doc->id, 'tipo_observacion' => 'otro', 'descripcion' => trim($datos['descripcion']),
                    'plazo_subsanacion' => $datos['plazo_subsanacion'], 'registrada_por' => $request->user()->getAuthIdentifier(), 'fecha_registro' => now(),
                ]);
            }
            $doc->update($cambios);
            if ($doc->estado === 'aceptado' && $doc->subsana_a_documento_id) {
                ObservacionDocumento::query()->where('documento_id', $doc->subsana_a_documento_id)->where('estado', 'abierta')->update([
                    'estado' => 'subsanada', 'documento_subsanacion_id' => $doc->id,
                    'fecha_subsanacion' => now(), 'verificada_por' => $request->user()->getAuthIdentifier(),
                    'comentario_cierre' => 'Subsanación aceptada por RR. HH.',
                ]);
            }
            HistorialEstadoDocumento::query()->create([
                'documento_usuario_id' => $doc->id, 'estado_anterior' => $antes['estado'], 'estado_nuevo' => $doc->estado,
                'cambiado_por' => $request->user()->getAuthIdentifier(), 'fecha_cambio' => now(), 'ip_origen' => $request->ip(),
                'observacion_id' => $observacion?->id, 'motivo' => $observacion?->descripcion ?? 'Revisión y recepción conforme de RR. HH.; fiscalización posterior independiente.',
            ]);
            AuditoriaDocumento::registrar($doc, $request, 'formacion.documento.'.$doc->estado, $antes);

            return $doc;
        });

        return response()->json(['data' => $registro->only(['id', 'estado', 'fedateado', 'fiscalizado'])]);
    }

    private function registrarDatos(DocumentoUsuario $doc, array $datos, string $letra): void
    {
        $base = ['usuario_id' => $doc->usuario_id, 'documento_id' => $doc->id, 'registrado_por' => $doc->created_by];
        if (in_array($letra, ['a', 'b', 'd', 'e'], true)) {
            FormacionAcademica::query()->create([...$base,
                'nivel' => $datos['nivel'] ?? ($letra === 'a' ? 'educacion_basica' : 'diplomado_especializacion'),
                'denominacion' => $doc->nombre, 'institucion' => $doc->emisor, 'fecha_obtencion' => $doc->fecha_emision,
                'numero_registro_sunedu' => $datos['numero_registro'] ?? null,
            ]);
        } elseif ($letra === 'c') {
            Colegiatura::query()->create([...$base, 'colegio_profesional' => $doc->emisor,
                'numero_colegiatura' => trim($datos['numero_colegiatura']), 'fecha_colegiatura' => $doc->fecha_emision,
                'vigente_hasta' => $datos['vigente_hasta'] ?? null, 'habilitado' => false,
            ]);
        } else {
            Capacitacion::query()->create([...$base, 'nombre' => $doc->nombre, 'entidad' => $doc->emisor,
                'tipo' => $datos['tipo_capacitacion'] ?? 'otro', 'horas' => $datos['horas'] ?? null, 'fecha_fin' => $doc->fecha_emision,
            ]);
        }
    }

    private function documento(DocumentoUsuario $doc, Request $request): array
    {
        return [...$doc->only(['id', 'nombre', 'emisor', 'num_resolucion', 'folio_inicio', 'folio_fin', 'fedateado', 'fiscalizado', 'modalidad_presentacion', 'subsana_a_documento_id']),
            'nombre' => $doc->nombre ?: ($doc->archivo?->nombre_original ?? 'Documento sin archivo'),
            'fecha_emision' => $doc->fecha_emision?->format('Y-m-d'), 'fecha_recepcion' => $doc->fecha_recepcion?->format('Y-m-d'),
            'estado' => ResumenSeccion03::estadoDocumento($doc->estado), 'estado_original' => $doc->estado,
            'subsanado' => $doc->documentosUsuarioPorSubsanaADocumento->isNotEmpty(),
            'puede_revisar' => $doc->created_by !== $request->user()->getAuthIdentifier() && $doc->estado !== 'anulado' && $doc->documentosUsuarioPorSubsanaADocumento->isEmpty(),
            'archivo' => $doc->archivo?->only(['id', 'nombre_original', 'mime_type', 'estado_antivirus', 'tamano_bytes']),
            'observaciones' => $doc->observacionesDocumentoPorDocumento->where('estado', 'abierta')->map(fn (ObservacionDocumento $obs): array => [
                'descripcion' => $obs->descripcion, 'plazo_subsanacion' => $obs->plazo_subsanacion?->format('Y-m-d'),
            ])->values()->all(),
        ];
    }

    private function legajo(Usuario $persona): ?Legajo
    {
        return $persona->legajos()->where('estado_legajo', 'activo')->orderByDesc('fecha_apertura')->orderByDesc('id')->first();
    }

    private function exigirSensibles(Request $request, TipoDocumento $tipo): void
    {
        abort_if(($tipo->es_dato_sensible || $tipo->es_dato_salud) && ! $request->user()->veDatosSensibles(), 403, 'Tu perfil no tiene acceso a este documento sensible.');
    }

    private function rechazarDesconocidos(Request $request, array $reglas): void
    {
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }
    }
}
