<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\Capacitacion;
use App\Models\Colegiatura;
use App\Models\DocumentoUsuario;
use App\Models\FormacionAcademica;
use App\Models\Legajo;
use App\Models\ObservacionDocumento;
use App\Models\Plataforma\Institucion;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\ArchivoPdf;
use App\Support\Documentos\AuditoriaDocumento;
use App\Support\Documentos\CargaDocumento;
use App\Support\Documentos\Folios;
use App\Support\Documentos\ReconocimientoTitulo;
use App\Support\Documentos\RevisionDocumento;
use App\Support\Parametros;
use App\Support\PerfilDocumental;
use App\Support\ResumenSeccion03;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class Seccion03Controller extends Controller
{
    public function show(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->with('vinculosLaborales')->findOrFail($usuario);
        $legajo = Legajo::paraConsulta($usuario);
        $perfil = PerfilDocumental::de($persona, $persona->vinculosLaborales);
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 3)->where('activo', true)
            ->whereIn('codigo', array_keys(ResumenSeccion03::ITEMS));
        if (! $request->user()->veDatosSensibles()) {
            $tipos->where('es_dato_sensible', false)->where('es_dato_salud', false);
        }
        $documentos = $legajo ? DocumentoUsuario::query()->where('legajo_id', $legajo->id)->where('seccion_legajo_id', 3)
            ->with(['archivo', 'observacionesDocumentoPorDocumento', 'documentosUsuarioPorSubsanaADocumento', 'formacionAcademica', 'colegiaturas', 'capacitaciones'])->orderByDesc('fecha_emision')->orderByDesc('id')->get() : collect();
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
            // Estudios básicos y colegiatura con su bandera en No no aplican (AplicacionDocumental).
            $aplica = $perfil->aplicacion->noAplica($tipo) === null;

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
            'legajo' => $legajo?->only(['id', 'numero_legajo', 'estado_legajo']), 'items' => $items->all(), 'resumen' => ResumenSeccion03::total($items),
            'folio_sugerido' => $legajo ? Folios::ultimo($legajo, 3) + 1 : null,
            'tamano_maximo_kb' => Parametros::entero('tamano_maximo_pdf_kb'),
        ]]);
    }

    public function reconocer(Request $request, string $usuario, ReconocimientoTitulo $lector): JsonResponse
    {
        $persona = Usuario::query()->findOrFail($usuario);
        abort_unless($this->legajo($persona), 409, 'Primero abre el legajo de esta persona.');
        $request->validate([
            'tipo_documento_id' => ['required', 'uuid', Rule::exists('tipos_documento', 'id')->where('seccion_legajo_id', 3)->where('activo', true)],
            'archivo' => ['required', 'file', 'max:'.Parametros::entero('tamano_maximo_pdf_kb'), 'mimetypes:application/pdf,image/jpeg,image/png'],
        ]);
        $tipo = TipoDocumento::query()->findOrFail($request->input('tipo_documento_id'));
        $this->exigirSensibles($request, $tipo);
        abort_unless(in_array($tipo->codigo, ['FORM_ESTUDIOS_BASICOS', 'FORM_TITULO_GRADO_SUPERIOR', 'FORM_MAESTRIA_DOCTORADO'], true), 422, 'La lectura asistida está disponible para certificados de estudios básicos, grados y títulos.');
        $archivo = $request->file('archivo');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($archivo->getRealPath());
        if (($mime === 'application/pdf' && ! ArchivoPdf::esPdf($archivo)) || ! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['archivo' => 'El contenido no es un PDF, JPG o PNG válido.']);
        }
        $lectura = $lector->extraer($archivo);
        $resultado = $lector->analizar($lectura['texto']);
        $normalizar = fn (string $valor): string => trim(preg_replace('/[^a-z ]/', '', Str::lower(Str::ascii($valor))));
        $titular = $normalizar($resultado['campos']['titular_nombre'] ?? '');
        $nombres = $normalizar($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno);
        if ($titular !== '' && array_diff(array_filter(explode(' ', $nombres)), explode(' ', $titular)) !== []) {
            $resultado['avisos'][] = 'El nombre leído no coincide completamente con el titular del legajo. Revisa la identidad antes de registrar.';
        }
        $recibo = [
            'usuario' => $usuario, 'actor' => (string) $request->user()->getAuthIdentifier(), 'tipo' => $tipo->id,
            'institucion' => app()->bound(Institucion::class) ? app(Institucion::class)->slug : 'unica',
            'hash' => ArchivoPdf::hash($request->file('archivo')), 'vence' => now()->addHours(2)->timestamp,
            'metodo' => $lectura['metodo'], 'paginas' => $lectura['paginas'], 'campos' => $resultado['campos'],
            'evidencias' => $resultado['evidencias'], 'fecha_lectura' => now()->toIso8601String(),
        ];

        return response()->json(['data' => [...$lectura, ...$resultado, 'token' => Crypt::encryptString(json_encode($recibo, JSON_THROW_ON_ERROR))]]);
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
        // Un tipo cuya bandera está en No lo rechaza CargaDocumento (AplicacionDocumental), con qué hacer.
        [$letra] = ResumenSeccion03::ITEMS[$tipo->codigo];
        $reglas = [
            'tipo_documento_id' => 'required|uuid', 'nombre' => 'required|string|max:200',
            'emisor' => 'required|string|max:200', 'fecha_emision' => 'required|date_format:Y-m-d|before_or_equal:today',
            'folio_inicio' => 'required|integer|min:1', 'folio_fin' => 'required|integer|gte:folio_inicio',
            'modalidad_presentacion' => ['required', Rule::in(DocumentoLegajoController::MODALIDADES)],
            'pdf' => ['required', 'file', 'max:'.Parametros::entero('tamano_maximo_pdf_kb')],
            'subsana_a_documento_id' => 'nullable|uuid',
        ];
        if (in_array($letra, ['a', 'b', 'e'], true)) {
            $reglas['numero_registro'] = $letra === 'a' ? 'prohibited' : 'nullable|string|max:40';
            $reglas['numero_diploma'] = 'nullable|string|max:40';
            $reglas['pais'] = 'nullable|string|max:60';
            $reglas['fecha_obtencion'] = 'nullable|date_format:Y-m-d|before_or_equal:today';
            $reglas['reconocimiento_token'] = 'nullable|string|max:20000';
            $reglas['reconocimiento_confirmado'] = 'exclude_without:reconocimiento_token|required|accepted';
            $reglas['nivel'] = $letra === 'a' ? 'sometimes|in:educacion_basica' : ['required', Rule::in($letra === 'b' ? ['tecnico', 'bachiller', 'titulo_profesional'] : ['maestria', 'doctorado'])];
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
        $reconocimiento = null;
        if (! empty($datos['reconocimiento_token'])) {
            try {
                $reconocimiento = json_decode(Crypt::decryptString($datos['reconocimiento_token']), true, flags: JSON_THROW_ON_ERROR);
                $slug = app()->bound(Institucion::class) ? app(Institucion::class)->slug : 'unica';
                if (! is_array($reconocimiento) || $reconocimiento['usuario'] !== $usuario || $reconocimiento['actor'] !== (string) $request->user()->getAuthIdentifier()
                    || $reconocimiento['tipo'] !== $tipo->id || $reconocimiento['institucion'] !== $slug || $reconocimiento['vence'] < now()->timestamp
                    || ! hash_equals($reconocimiento['hash'], ArchivoPdf::hash($request->file('pdf')))) {
                    throw new \RuntimeException('Lectura no vigente.');
                }
            } catch (Throwable) {
                throw ValidationException::withMessages(['reconocimiento_token' => 'La lectura no corresponde a este archivo o ha vencido. Reconoce el documento nuevamente o ingresa los datos manualmente.']);
            }
        }
        $metadatos = collect($datos)->only(['nombre', 'emisor', 'fecha_emision', 'folio_inicio', 'folio_fin', 'modalidad_presentacion'])->map(fn ($valor) => is_string($valor) ? trim($valor) : $valor)->all();
        $metadatos = [...$metadatos,
            'num_resolucion' => $datos['numero_registro'] ?? null, 'fecha_incorporacion' => today()->toDateString(),
            'titular_nombre' => mb_substr(trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno), 0, 200),
            'titular_documento' => $persona->numero_doc_identidad, 'relacion_titular' => 'servidor',
        ];
        $documento = CargaDocumento::registrar($legajo, $tipo, $request->file('pdf'), $metadatos, $request->user()->getAuthIdentifier(), $request->ip(),
            $datos['subsana_a_documento_id'] ?? null, completar: function (DocumentoUsuario $doc) use ($datos, $letra, $request, $reconocimiento): void {
                abort_unless($doc->legajo->estado_legajo === 'activo', 409, 'El legajo se cerró durante la carga. Actualiza la ficha.');
                $this->registrarDatos($doc, $datos, $letra);
                AuditoriaDocumento::registrar($doc, $request, 'formacion.documento.creado');
                if ($reconocimiento !== null) {
                    AuditoriaDocumento::registrar($doc, $request, 'formacion.reconocimiento.confirmado', $reconocimiento,
                        [...collect($datos)->except(['pdf', 'reconocimiento_token', 'reconocimiento_confirmado'])->all(), 'hash_archivo' => $reconocimiento['hash'], 'revision_humana' => true]);
                }
            });

        return response()->json(['data' => $documento->only(['id', 'nombre', 'estado', 'folio_inicio', 'folio_fin'])], 201);
    }

    public function cambiarEstado(Request $request, string $usuario, string $documento): JsonResponse
    {
        return RevisionDocumento::cambiarEstado($request, $usuario, $documento, 3, 'formacion.documento');
    }

    /** @return array<string, mixed> */
    private function datosRegistro(DocumentoUsuario $doc): array
    {
        $formacion = $doc->formacionAcademica->first();
        $colegiatura = $doc->colegiaturas->first();
        $capacitacion = $doc->capacitaciones->first();

        return array_filter([
            'nivel' => $formacion?->nivel, 'numero_registro' => $formacion?->numero_registro_sunedu,
            'pais' => $formacion?->pais, 'numero_diploma' => $formacion?->numero_diploma, 'fecha_obtencion' => $formacion?->fecha_obtencion?->format('Y-m-d'),
            'numero_colegiatura' => $colegiatura?->numero_colegiatura, 'vigente_hasta' => $colegiatura?->vigente_hasta?->format('Y-m-d'),
            'tipo_capacitacion' => $capacitacion?->tipo, 'horas' => $capacitacion?->horas,
        ], fn (mixed $valor): bool => $valor !== null);
    }

    /** @return array{formacion: list<array<string, mixed>>, colegiaturas: list<array<string, mixed>>, capacitaciones: list<array<string, mixed>>} */
    private function registros(DocumentoUsuario $doc): array
    {
        $fecha = fn (?CarbonInterface $valor): ?string => $valor?->format('Y-m-d');

        return [
            'formacion' => $doc->formacionAcademica->map(fn (FormacionAcademica $f): array => [
                ...$f->only(['id', 'nivel', 'denominacion', 'institucion', 'pais', 'es_revalidado', 'numero_registro_sunedu', 'numero_diploma', 'anulado']),
                'fecha_inicio' => $fecha($f->fecha_inicio), 'fecha_obtencion' => $fecha($f->fecha_obtencion),
            ])->values()->all(),
            'colegiaturas' => $doc->colegiaturas->map(fn (Colegiatura $c): array => [
                ...$c->only(['id', 'colegio_profesional', 'numero_colegiatura', 'habilitado', 'anulado']),
                'fecha_colegiatura' => $fecha($c->fecha_colegiatura), 'fecha_verificacion' => $fecha($c->fecha_verificacion), 'vigente_hasta' => $fecha($c->vigente_hasta),
            ])->values()->all(),
            'capacitaciones' => $doc->capacitaciones->map(fn (Capacitacion $c): array => [
                ...$c->only(['id', 'tipo', 'nombre', 'entidad', 'horas', 'anulado']),
                'fecha_inicio' => $fecha($c->fecha_inicio), 'fecha_fin' => $fecha($c->fecha_fin),
            ])->values()->all(),
        ];
    }

    private function registrarDatos(DocumentoUsuario $doc, array $datos, string $letra): void
    {
        $base = ['usuario_id' => $doc->usuario_id, 'documento_id' => $doc->id, 'registrado_por' => $doc->created_by];
        if (in_array($letra, ['a', 'b', 'd', 'e'], true)) {
            FormacionAcademica::query()->create([...$base,
                'nivel' => $datos['nivel'] ?? ($letra === 'a' ? 'educacion_basica' : 'diplomado_especializacion'),
                'denominacion' => $doc->nombre, 'institucion' => $doc->emisor, 'fecha_obtencion' => $datos['fecha_obtencion'] ?? $doc->fecha_emision,
                'pais' => $datos['pais'] ?? 'No determinado', 'numero_diploma' => $datos['numero_diploma'] ?? null,
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
        return [...$doc->only(['id', 'nombre', 'emisor', 'num_resolucion', 'folio_inicio', 'folio_fin', 'fedateado', 'fiscalizado', 'resultado_fiscalizacion', 'proceso_fiscalizacion_id', 'modalidad_presentacion', 'subsana_a_documento_id']),
            'nombre' => $doc->nombre ?: ($doc->archivo?->nombre_original ?? 'Documento sin archivo'),
            'fecha_emision' => $doc->fecha_emision?->format('Y-m-d'), 'fecha_recepcion' => $doc->fecha_recepcion?->format('Y-m-d'),
            'estado' => ResumenSeccion03::estadoDocumento($doc->estado), 'estado_original' => $doc->estado,
            'subsanado' => $doc->documentosUsuarioPorSubsanaADocumento->isNotEmpty(),
            'puede_revisar' => $doc->created_by !== $request->user()->getAuthIdentifier() && $doc->estado !== 'anulado' && $doc->documentosUsuarioPorSubsanaADocumento->isEmpty(),
            'archivo' => $doc->archivo?->only(['id', 'nombre_original', 'mime_type', 'estado_antivirus', 'tamano_bytes']),
            // Datos del registro que creó el documento, con los nombres del formulario: la subsanación los propone.
            'datos' => $this->datosRegistro($doc),
            // Lo que el documento acredita, para el resumen de formación del panel (antes solo en los listados genéricos).
            'registros' => $this->registros($doc),
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
