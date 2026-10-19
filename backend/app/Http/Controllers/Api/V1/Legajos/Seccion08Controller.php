<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\Reconocimiento;
use App\Models\RecursoImpugnatorio;
use App\Models\Sancion;
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
use App\Support\ResumenSeccion08;
use App\Support\TiempoExperiencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Seccion08Controller extends Controller
{
    private const TIPOS_RECONOCIMIENTO = ['REC_CARTA_FELICITACION', 'REC_LABOR_DOCENTE', 'REC_TIEMPO_SERVICIOS'];

    private const TIPOS_SANCION = ['SAN_RESOLUCION_SANCION', 'SAN_RESOLUCION_RECURSO'];

    public function show(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->with('vinculosLaborales')->findOrFail($usuario);
        $legajo = Legajo::paraConsulta($usuario);
        $perfil = PerfilDocumental::de($persona, $persona->vinculosLaborales);
        $tiempoExperiencia = TiempoExperiencia::paraPersona($usuario, permitirSensibles: $request->user()->veDatosSensibles());
        $puedeVerSanciones = $request->user()->can('seccion08.sanciones.ver')
            || $request->user()->can('seccion08.sanciones.registrar')
            || $request->user()->can('seccion08.sanciones.revisar');
        $tiposPermitidos = [...self::TIPOS_RECONOCIMIENTO, ...($puedeVerSanciones ? self::TIPOS_SANCION : [])];
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 8)->where('activo', true)->whereIn('codigo', $tiposPermitidos)->orderBy('orden')->get();
        $documentos = $legajo ? DocumentoUsuario::query()->where('legajo_id', $legajo->id)->where('seccion_legajo_id', 8)
            ->whereIn('tipo_documento_id', $tipos->pluck('id'))->with(['tipoDocumento', 'archivo', 'reconocimientos', 'tiempoServiciosReconocidos', 'sanciones.recursosImpugnatorios.documento.archivo',
                'observacionesDocumentoPorDocumento', 'documentosUsuarioPorSubsanaADocumento'])->orderBy('fecha_emision')->orderBy('id')->get() : collect();
        $items = $tipos->map(function (TipoDocumento $tipo) use ($perfil, $documentos, $request): array {
            $codigo = $tipo->codigo;
            $noAplica = $tipo->requiere_docente && ! in_array('docente', $perfil->tiposPersonal, true);
            $filas = $documentos->where('tipo_documento_id', $tipo->id)->map(fn (DocumentoUsuario $doc): array => $this->fila($doc, $request))->values();
            [$letra, $grupo] = ResumenSeccion08::ITEMS[$codigo];

            return [...$tipo->only(['id', 'codigo', 'nombre', 'permite_multiples', 'descripcion', 'requiere_docente', 'requiere_num_resolucion']),
                'letra' => $letra, 'grupo' => $grupo, 'aplica' => ! $noAplica,
                'motivo' => $noAplica ? 'No aplica al personal no docente.' : match ($codigo) {
                    'REC_CARTA_FELICITACION' => 'Opcional; se registra cuando se cuenta con alguno de estos documentos.',
                    'REC_LABOR_DOCENTE' => 'Opcional; se registra cuando existe un reconocimiento excepcional por labor docente.',
                    'REC_TIEMPO_SERVICIOS' => 'Opcional; se registra cuando existe un reconocimiento por tiempo de servicios.',
                    'SAN_RESOLUCION_SANCION' => 'Opcional; se registra cuando existe documentación de una sanción disciplinaria.',
                    default => 'Opcional; se registra cuando existe una decisión sobre un recurso impugnatorio.',
                },
                'estado' => ResumenSeccion08::estado($filas, $noAplica),
                'cantidad' => $filas->reject(fn (array $doc): bool => $doc['subsanado'] || $doc['estado'] === 'anulado')->count(),
                'documentos' => $filas->all()];
        });
        $todosLosItems = $items;
        $items = $items->groupBy('grupo');

        return response()->json(['data' => [
            'persona' => $persona->only(['id', 'tipo_personal', 'nombre_puesto']),
            'perfil' => ['tipos_personal' => $perfil->tiposPersonal, 'puestos' => $perfil->puestos],
            'legajo' => $legajo?->only(['id', 'numero_legajo', 'estado_legajo']),
            'grupos' => ['reconocimientos' => $items->get('reconocimientos', collect())->values(), 'sanciones' => $puedeVerSanciones ? $items->get('sanciones', collect())->values() : null],
            'puede_ver_sanciones' => $puedeVerSanciones,
            'resumen' => ResumenSeccion08::resumen($todosLosItems, $puedeVerSanciones),
            'folio_sugerido' => $legajo ? Folios::ultimo($legajo, 8) + 1 : null,
            'tiempo_experiencia_calculada' => collect($tiempoExperiencia['total'])->only(['anios', 'meses', 'dias'])->all(),
            'fecha_actual' => now('America/Lima')->toDateString(),
            'tamano_maximo_kb' => Parametros::entero('tamano_maximo_pdf_kb'),
        ]]);
    }

    public function store(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->with('vinculosLaborales')->findOrFail($usuario);
        $legajo = Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->orderByDesc('fecha_apertura')->first();
        abort_unless($legajo, 409, 'Primero abre el legajo para agregar documentos.');
        $base = ['tipo_documento_id' => ['required', 'uuid', Rule::exists('tipos_documento', 'id')->where('seccion_legajo_id', 8)->where('activo', true)]];
        $id = $request->validate($base)['tipo_documento_id'];
        $tipo = TipoDocumento::query()->findOrFail($id);
        $codigo = $tipo->codigo;
        abort_unless(isset(ResumenSeccion08::ITEMS[$codigo]), 422, 'Selecciona un tipo de documento de la Sección 08.');
        $esSancion = in_array($codigo, self::TIPOS_SANCION, true);
        if ($esSancion) {
            abort_unless($request->user()->can('seccion08.sanciones.registrar'), 403, 'Tu perfil no tiene permiso para registrar sanciones.');
        } else {
            abort_unless($request->user()->can('documento.subir'), 403, 'Tu perfil no tiene permiso para subir documentos al legajo.');
        }

        $hoy = now('America/Lima')->toDateString();
        $reglas = [
            'tipo_documento_id' => 'required|uuid', 'nombre' => 'required|string|max:200', 'emisor' => 'required|string|max:200',
            'fecha_emision' => 'required|date_format:Y-m-d|before_or_equal:'.$hoy, 'num_resolucion' => [$tipo->requiere_num_resolucion ? 'required' : 'nullable', 'string', 'max:60'],
            'folio_inicio' => 'required|integer|between:1,2147483647', 'folio_fin' => 'required|integer|between:1,2147483647|gte:folio_inicio',
            'modalidad_presentacion' => ['required', Rule::in(DocumentoLegajoController::MODALIDADES)],
            'pdf' => ['required', 'file', 'max:'.Parametros::entero('tamano_maximo_pdf_kb')],
            'subsana_a_documento_id' => 'nullable|uuid',
        ];
        if (in_array($codigo, self::TIPOS_RECONOCIMIENTO, true)) {
            $reglas += ['motivo' => 'required|string|max:300', 'tipo' => ['required', Rule::in(['carta', 'labor_docente', 'tiempo_servicios'])]];
            if ($codigo === 'REC_TIEMPO_SERVICIOS') {
                foreach (['anios', 'meses', 'dias'] as $campo) {
                    $reglas[$campo] = 'nullable|integer|between:0,2147483647';
                }
            }
        }
        if ($codigo === 'SAN_RESOLUCION_SANCION') {
            $reglas += ['tipo' => ['required', Rule::in(['AMONESTACION_ESCRITA', 'SUSPENSION', 'DESTITUCION'])],
                'dias_suspension' => ['required_if:tipo,SUSPENSION', 'prohibited_unless:tipo,SUSPENSION', 'nullable', 'integer', 'between:1,2147483647'],
                'registrada_en_rnssc' => 'required|boolean', 'fecha_registro_rnssc' => 'nullable|date_format:Y-m-d|before_or_equal:'.$hoy,
                'referencia_rnssc' => 'nullable|string', 'referencia_procedimiento' => 'nullable|string'];
        }
        if ($codigo === 'SAN_RESOLUCION_RECURSO') {
            $reglas += ['sancion_id' => ['required', 'uuid', Rule::exists('sanciones', 'id')->where('usuario_id', $usuario)],
                'resultado' => ['required', Rule::in(['FUNDADO', 'INFUNDADO', 'IMPROCEDENTE'])]];
        }
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }
        $datos = $request->validate($reglas, ['folio_fin.gte' => 'El folio final debe ser igual o mayor que el inicial.',
            'pdf.max' => 'El PDF supera el tamaño permitido. Reduce su tamaño e intenta nuevamente.']);
        if ($codigo === 'REC_TIEMPO_SERVICIOS') {
            $duracionIngresada = collect(['anios', 'meses', 'dias'])->contains(fn (string $campo): bool => array_key_exists($campo, $datos) && $datos[$campo] !== null);
            if ($duracionIngresada) {
                foreach (['anios', 'meses', 'dias'] as $campo) {
                    if (! array_key_exists($campo, $datos) || $datos[$campo] === null) {
                        throw ValidationException::withMessages([$campo => 'Completa años, meses y días, o deja los tres campos vacíos.']);
                    }
                }
            }
        }
        if ($codigo === 'SAN_RESOLUCION_RECURSO') {
            $sancionVinculada = Sancion::query()->where('usuario_id', $usuario)->whereKey($datos['sancion_id'])
                ->whereHas('documento', fn ($query) => $query->where('seccion_legajo_id', 8)->whereHas('tipoDocumento', fn ($tipoQuery) => $tipoQuery->where('codigo', 'SAN_RESOLUCION_SANCION')))->exists();
            if (! $sancionVinculada) {
                throw ValidationException::withMessages(['sancion_id' => 'Selecciona una sanción registrada en la Sección 08 para esta persona.']);
            }
        }
        if ($tipo->requiere_docente) {
            $perfil = PerfilDocumental::de($persona, $persona->vinculosLaborales);
            abort_unless(in_array('docente', $perfil->tiposPersonal, true), 422,
                'Este reconocimiento solo aplica al personal docente.');
        }
        if (! ArchivoPdf::esPdf($request->file('pdf'))) {
            throw ValidationException::withMessages(['pdf' => 'Selecciona un archivo que contenga un PDF válido.']);
        }
        foreach (['nombre', 'emisor', 'num_resolucion', 'motivo'] as $campo) {
            if (isset($datos[$campo])) {
                $datos[$campo] = trim($datos[$campo]);
                if ($datos[$campo] === '') {
                    throw ValidationException::withMessages([$campo => 'Completa este dato antes de guardar.']);
                }
            }
        }
        $meta = collect($datos)->only(['nombre', 'emisor', 'fecha_emision', 'num_resolucion', 'folio_inicio', 'folio_fin', 'modalidad_presentacion'])->all();
        $meta += ['fecha_incorporacion' => $hoy, 'titular_nombre' => mb_substr(trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 0, 200),
            'titular_documento' => $persona->numero_doc_identidad, 'relacion_titular' => 'servidor'];
        $documento = CargaDocumento::registrar($legajo, $tipo, $request->file('pdf'), $meta, $request->user()->getAuthIdentifier(), $request->ip(),
            $datos['subsana_a_documento_id'] ?? null, completar: function (DocumentoUsuario $doc) use ($datos, $codigo, $request): void {
                if (in_array($codigo, self::TIPOS_RECONOCIMIENTO, true)) {
                    Reconocimiento::query()->create(['usuario_id' => $doc->usuario_id, 'documento_id' => $doc->id, 'tipo' => $datos['tipo'],
                        'fecha' => $doc->fecha_emision, 'motivo' => trim($datos['motivo']), 'otorgado_por' => $doc->emisor,
                        'numero_documento' => $doc->num_resolucion, 'registrado_por' => $request->user()->getAuthIdentifier()]);
                    if ($codigo === 'REC_TIEMPO_SERVICIOS' && isset($datos['anios'], $datos['meses'], $datos['dias'])) {
                        TiempoServicioReconocido::query()->create([
                            'usuario_id' => $doc->usuario_id, 'documento_id' => $doc->id, 'registrado_por' => $doc->created_by,
                            'numero_resolucion' => $doc->num_resolucion, 'fecha_resolucion' => $doc->fecha_emision,
                            ...collect($datos)->only(['anios', 'meses', 'dias'])->all(),
                        ]);
                    }
                } elseif ($codigo === 'SAN_RESOLUCION_SANCION') {
                    Sancion::query()->create(['usuario_id' => $doc->usuario_id, 'documento_id' => $doc->id, 'tipo' => $datos['tipo'],
                        'numero_resolucion' => $doc->num_resolucion, 'fecha_resolucion' => $doc->fecha_emision,
                        'dias_suspension' => $datos['dias_suspension'] ?? null, 'registrada_en_rnssc' => $datos['registrada_en_rnssc'],
                        'fecha_registro_rnssc' => $datos['fecha_registro_rnssc'] ?? null, 'referencia_rnssc' => $datos['referencia_rnssc'] ?? null,
                        'referencia_procedimiento' => $datos['referencia_procedimiento'] ?? null,
                        'registrado_por' => $request->user()->getAuthIdentifier()]);
                } else {
                    $sancion = Sancion::query()->where('usuario_id', $doc->usuario_id)->lockForUpdate()->findOrFail($datos['sancion_id']);
                    RecursoImpugnatorio::query()->create(['sancion_id' => $sancion->id, 'documento_id' => $doc->id,
                        'fecha' => $doc->fecha_emision, 'resultado' => $datos['resultado'], 'registrado_por' => $request->user()->getAuthIdentifier()]);
                }
                AuditoriaDocumento::registrar($doc, $request, 'seccion08.documento.creado');
            });

        return response()->json(['data' => $documento->only(['id', 'nombre', 'estado', 'folio_inicio', 'folio_fin'])], 201);
    }

    public function cambiarEstado(Request $request, string $usuario, string $documento): JsonResponse
    {
        $doc = DocumentoUsuario::query()->where('usuario_id', $usuario)->where('seccion_legajo_id', 8)->with('tipoDocumento')->findOrFail($documento);
        if (in_array($doc->tipoDocumento->codigo, self::TIPOS_SANCION, true)) {
            abort_unless($request->user()->can('seccion08.sanciones.revisar'), 403, 'Tu perfil no tiene permiso para revisar documentos de sanciones.');
        } else {
            abort_unless($request->user()->can('documento.revisar'), 403, 'Tu perfil no tiene permiso para revisar documentos.');
        }

        return RevisionDocumento::cambiarEstado($request, $usuario, $documento, 8, 'seccion08.documento');
    }

    private function fila(DocumentoUsuario $doc, Request $request): array
    {
        $reconocimiento = $doc->reconocimientos->first();
        $tiempoReconocido = $doc->tiempoServiciosReconocidos->firstWhere('usuario_id', $doc->usuario_id);
        $sancion = $doc->sanciones->first();
        $recursos = $sancion?->recursosImpugnatorios ?? collect();
        $hasSancion = $sancion !== null;

        return [...$doc->only(['id', 'nombre', 'emisor', 'num_resolucion', 'folio_inicio', 'folio_fin', 'fedateado', 'fiscalizado', 'modalidad_presentacion']),
            'nombre' => $doc->nombre ?: ($doc->archivo?->nombre_original ?? 'Documento sin archivo'),
            'fecha_emision' => $doc->fecha_emision?->format('Y-m-d'), 'estado' => ResumenSeccion03::estadoDocumento($doc->estado),
            'estado_original' => $doc->estado, 'fecha_recepcion' => $doc->fecha_recepcion?->format('Y-m-d'), 'tipo_documento_codigo' => $doc->tipoDocumento->codigo,
            'grupo' => ResumenSeccion08::ITEMS[$doc->tipoDocumento->codigo][1],
            'subsanado' => $doc->documentosUsuarioPorSubsanaADocumento->isNotEmpty(),
            'puede_revisar' => $doc->created_by !== $request->user()->getAuthIdentifier() && $doc->estado !== 'anulado' && $doc->documentosUsuarioPorSubsanaADocumento->isEmpty(),
            'archivo' => $doc->archivo?->only(['id', 'nombre_original', 'mime_type', 'estado_antivirus', 'tamano_bytes']),
            'observaciones' => $doc->observacionesDocumentoPorDocumento->where('estado', 'abierta')->map(fn ($obs): array => ['descripcion' => $obs->descripcion, 'plazo_subsanacion' => $obs->plazo_subsanacion?->format('Y-m-d')])->values()->all(),
            'motivo' => $reconocimiento?->motivo, 'tipo_reconocimiento' => $reconocimiento?->tipo,
            'tiempo_servicio_reconocido' => $tiempoReconocido?->only(['anios', 'meses', 'dias']),
            'registro_sancion_id' => $sancion?->id, 'tipo_sancion' => $sancion?->tipo, 'dias_suspension' => $sancion?->dias_suspension,
            'estado_sancion' => $hasSancion ? ResumenSeccion08::estadoSancion($recursos) : null,
            'registrada_en_rnssc' => $sancion?->registrada_en_rnssc,
            'fecha_registro_rnssc' => $sancion?->fecha_registro_rnssc?->format('Y-m-d'), 'referencia_rnssc' => $sancion?->referencia_rnssc,
            'referencia_procedimiento' => $sancion?->referencia_procedimiento,
            'recursos' => $recursos->map(fn (RecursoImpugnatorio $recurso): array => [
                'id' => $recurso->id, 'fecha' => $recurso->fecha->format('Y-m-d'), 'resultado' => $recurso->resultado,
                'documento_id' => $recurso->documento_id, 'nombre_documento' => $recurso->documento?->nombre,
            ])->all(),
        ];
    }
}
