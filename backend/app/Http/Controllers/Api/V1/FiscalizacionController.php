<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\ProcesoFiscalizacion;
use App\Models\SolicitudFiscalizacion;
use App\Models\Usuario;
use App\Support\Auditoria;
use App\Support\CorreoFiscalizacion;
use App\Support\Documentos\AuditoriaDocumento;
use App\Support\Documentos\ReconocimientoConsulta;
use App\Support\Documentos\ReconocimientoTitulo;
use App\Support\Parametros;
use App\Support\VerificacionFiscalizacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class FiscalizacionController extends Controller
{
    private const RESULTADOS = ['conforme', 'observado', 'no_concluyente', 'no_autentico'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'nullable|string|max:120', 'estado' => 'nullable|in:abierto,en_verificacion,en_revision,cerrado', 'page' => 'sometimes|integer|min:1']);
        $query = $this->consultaVisible($request);
        $totales = (clone $query)->select('estado', DB::raw('count(*) as total'))->groupBy('estado')->pluck('total', 'estado');
        if ($data['estado'] ?? null) {
            $query->where('estado', $data['estado']);
        }
        if ($texto = mb_strtolower(trim($data['q'] ?? ''))) {
            $query->where(function (Builder $q) use ($texto): void {
                $q->whereRaw('LOWER(codigo) like ?', ['%'.$texto.'%'])
                    ->orWhereHas('usuario', fn (Builder $u) => $u->whereRaw("LOWER(concat(nombres, ' ', apellido_paterno, ' ', apellido_materno)) like ?", ['%'.$texto.'%']))
                    ->orWhereHas('legajo', fn (Builder $l) => $l->whereRaw('LOWER(numero_legajo) like ?', ['%'.$texto.'%']));
            });
        }
        $listado = $query->with(['usuario:id,nombres,apellido_paterno,apellido_materno', 'legajo:id,numero_legajo', 'responsable:id,nombres,apellido_paterno,apellido_materno'])
            ->latest('created_at')->orderByDesc('id')->paginate(20);

        return response()->json(['data' => $listado->items(), 'total' => $listado->total(), 'current_page' => $listado->currentPage(), 'last_page' => $listado->lastPage(), 'resumen' => $totales]);
    }

    public function opciones(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'nullable|string|max:120', 'legajo_id' => 'nullable|uuid|exists:legajos,id']);
        $texto = mb_strtolower(trim($data['q'] ?? ''));
        $legajos = Legajo::query()->with('usuario:id,nombres,apellido_paterno,apellido_materno')
            ->where('estado_legajo', 'activo')->where(function (Builder $q) use ($texto): void {
                $q->whereRaw('LOWER(numero_legajo) like ?', ['%'.$texto.'%'])
                    ->orWhereHas('usuario', fn (Builder $u) => $u->whereRaw("LOWER(concat(nombres, ' ', apellido_paterno, ' ', apellido_materno)) like ?", ['%'.$texto.'%']));
            })->orderBy('numero_legajo')->limit(40)->get(['id', 'usuario_id', 'numero_legajo', 'fecha_apertura']);
        $documentos = isset($data['legajo_id']) ? $this->documentosVisibles($request)->where('legajo_id', $data['legajo_id'])->with('tipoDocumento:id,nombre')->orderBy('seccion_legajo_id')->orderBy('folio_inicio')->get() : collect();
        $rolesRevisores = collect(config('permisos.roles'))->filter(fn (array $permisos): bool => in_array('*', $permisos, true) || in_array('fiscalizacion.aprobar', $permisos, true))->keys()->all();
        $revisores = Usuario::query()->where('activo', true)->whereHas('usuarioRolesPorUsuario', fn (Builder $r) => $r->whereHas('rol', fn (Builder $rol) => $rol->whereIn('codigo', $rolesRevisores)))->orderBy('apellido_paterno')->limit(200)->get()
            ->filter(fn (Usuario $u): bool => $u->can('fiscalizacion.aprobar'))->map(fn (Usuario $u): array => $u->only(['id', 'nombres', 'apellido_paterno', 'apellido_materno']))->values();

        return response()->json(['legajos' => $legajos, 'documentos' => $documentos->map(fn ($doc) => $this->serializarDocumento($doc)), 'revisores' => $revisores, 'servicios' => VerificacionFiscalizacion::servicios()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legajo_id' => 'required|uuid|exists:legajos,id', 'tipo_muestra' => 'required|in:integral,aleatoria,dirigida',
            'fecha_vinculacion' => 'required|date_format:Y-m-d|before_or_equal:'.now('America/Lima')->toDateString(),
            'fecha_inicio' => 'required|date_format:Y-m-d|after_or_equal:fecha_vinculacion|before_or_equal:'.now('America/Lima')->toDateString(),
            'fecha_limite' => 'required|date_format:Y-m-d|after_or_equal:fecha_inicio',
            'motivo' => 'required|string|max:3000', 'fundamento' => 'required|string|max:3000',
            'documentos' => 'required|array|min:1|max:100', 'documentos.*' => 'required|uuid|distinct|exists:documentos_usuario,id',
        ]);
        $proceso = DB::transaction(function () use ($request, $data): ProcesoFiscalizacion {
            $legajo = Legajo::query()->lockForUpdate()->findOrFail($data['legajo_id']);
            abort_unless($legajo->estado_legajo === 'activo', 409, 'Selecciona un legajo activo.');
            $proceso = ProcesoFiscalizacion::query()->create([
                ...collect($data)->except('documentos')->all(), 'usuario_id' => $legajo->usuario_id,
                'responsable_id' => $request->user()->id, 'estado' => 'abierto', 'codigo' => 'FIS-'.now('America/Lima')->format('Y').'-'.strtoupper(Str::random(10)),
            ]);
            foreach ($data['documentos'] as $id) {
                $this->incorporar($request, $proceso, $id);
            }
            $this->evento($request, $proceso, 'apertura', ['documentos' => $data['documentos'], 'motivo' => $data['motivo']]);

            return $proceso;
        });

        return response()->json(['data' => $proceso], 201);
    }

    public function show(Request $request, string $proceso): JsonResponse
    {
        $p = $this->proceso($request, $proceso);
        $p->load(['usuario:id,nombres,apellido_paterno,apellido_materno', 'legajo:id,numero_legajo,estado_legajo', 'responsable:id,nombres,apellido_paterno,apellido_materno']);
        $alcance = DB::table('fiscalizacion_documentos')->where('proceso_id', $p->id)->orderBy('created_at')->orderBy('id')->get();
        $verificaciones = DB::table('fiscalizacion_verificaciones')->whereIn('alcance_id', $alcance->pluck('id'))->orderByDesc('created_at')->orderByDesc('id')->get()->map(function ($v) {
            $v->detalle = json_decode($v->detalle, true);

            return $v;
        })->groupBy('alcance_id');
        foreach ($alcance as $fila) {
            $fila->datos_snapshot = json_decode($fila->datos_snapshot, true);
            $fila->verificaciones = $verificaciones->get($fila->id, collect());
        }
        $historial = AuditoriaEvento::query()->where('entidad', 'procesos_fiscalizacion')->where('entidad_id', $p->id)->orderBy('fecha_evento')->orderBy('id')
            ->get(['id', 'accion', 'actor_usuario_id', 'fecha_evento', 'datos_despues'])->map(function ($e): array {
                return [...$e->only(['id', 'accion', 'actor_usuario_id', 'fecha_evento']), 'detalle' => json_decode($e->datos_despues ?? '{}', true)];
            });

        $autores = Usuario::query()->whereIn('id', $historial->pluck('actor_usuario_id')->filter())->get(['id', 'nombres', 'apellido_paterno', 'apellido_materno'])->keyBy('id');
        $historial = $historial->map(fn (array $e): array => [...$e, 'autor' => $autores->get($e['actor_usuario_id'])]);

        return response()->json(['data' => $p, 'alcance' => $alcance, 'consultas' => $p->solicitudesFiscalizacion()->orderBy('fecha_envio')->orderBy('id')->get(),
            'derivaciones' => DB::table('fiscalizacion_derivaciones')->where('proceso_id', $p->id)->orderBy('created_at')->get(),
            'cierre_responsable' => $historial->contains('accion', 'fiscalizacion.cierre_responsable'),
            'correos' => DB::table('fiscalizacion_correos')->where('proceso_id', $p->id)->orderByDesc('created_at')->get(),
            'historial' => $historial, 'servicios' => VerificacionFiscalizacion::servicios(),
            'revisor' => $p->revisor_id ? Usuario::query()->find($p->revisor_id)?->only(['id', 'nombres', 'apellido_paterno', 'apellido_materno']) : null,
            'documentos' => $this->documentosVisibles($request)->where('legajo_id', $p->legajo_id)->with('tipoDocumento:id,nombre')->orderBy('seccion_legajo_id')->orderBy('folio_inicio')->get()->map(fn ($doc) => $this->serializarDocumento($doc)),
        ]);
    }

    public function agregarDocumento(Request $request, string $proceso): JsonResponse
    {
        $data = $request->validate(['documento_id' => 'required|uuid|exists:documentos_usuario,id']);
        DB::transaction(function () use ($request, $proceso, $data): void {
            $p = $this->proceso($request, $proceso, true);
            $this->editable($p);
            $this->incorporar($request, $p, $data['documento_id']);
            $this->evento($request, $p, 'ampliacion_alcance', $data);
        });

        return response()->json(['message' => 'Documento incorporado al alcance.'], 201);
    }

    public function verificar(Request $request, string $proceso): JsonResponse
    {
        $data = $request->validate(['fuente' => 'required|in:integridad,consistencia,firma_peru,apisted_minedu,apisted_sunedu', 'alcances' => 'required|array|min:1|max:1', 'alcances.*' => 'required|uuid|distinct']);
        if (in_array($data['fuente'], ['apisted_minedu', 'apisted_sunedu'], true)) {
            $request->validate(['confirmar_creditos' => 'required|accepted']);
        }
        $resultados = [];
        foreach ($data['alcances'] as $id) {
            $reserva = DB::transaction(function () use ($request, $proceso, $id, $data): array {
                $p = $this->proceso($request, $proceso, true);
                $this->editable($p);
                $this->responsable($request, $p);
                $alcance = DB::table('fiscalizacion_documentos')->where('proceso_id', $p->id)->where('id', $id)->first();
                abort_unless($alcance, 404);
                $doc = $this->documento($request, $p, $alcance->documento_id);
                abort_unless($doc->archivo_id === $alcance->archivo_id, 409, 'El archivo cambió respecto al alcance. Revisa el proceso.');
                DB::table('fiscalizacion_verificaciones')->where('alcance_id', $id)->where('estado_tecnico', 'en_curso')->where('created_at', '<', now()->subMinutes(5))->update(['estado_tecnico' => 'no_disponible', 'resultado_fuente' => 'consulta_interrumpida']);
                $reciente = DB::table('fiscalizacion_verificaciones')->where('alcance_id', $id)->where('fuente', $data['fuente'])
                    ->where(function ($q): void {
                        $q->where('created_at', '>=', now()->subSeconds(30))->orWhere(fn ($curso) => $curso->where('estado_tecnico', 'en_curso')->where('created_at', '>=', now()->subMinutes(5)));
                    })->exists();
                abort_if($reciente, 409, 'Ya existe una consulta reciente de esa fuente. Espera antes de reintentar.');
                $verificacion = (string) Str::uuid();
                DB::table('fiscalizacion_verificaciones')->insert(['id' => $verificacion, 'alcance_id' => $id, 'fuente' => $data['fuente'], 'estado_tecnico' => 'en_curso',
                    'resultado_fuente' => 'pendiente', 'detalle' => '{}', 'registrado_por' => $request->user()->id, 'created_at' => now()]);
                $p->update(['estado' => 'en_verificacion']);
                $this->evento($request, $p, 'consulta_iniciada', ['verificacion_id' => $verificacion, 'fuente' => $data['fuente'], 'documento_id' => $doc->id]);

                return [$p, $doc, $alcance, $verificacion];
            });
            [$p, $doc, $alcance, $verificacion] = $reserva;
            $resultado = VerificacionFiscalizacion::ejecutar($doc, $data['fuente'], $alcance->hash_sha256);
            DB::transaction(function () use ($request, $p, $verificacion, $resultado): void {
                $p = $this->proceso($request, $p->id, true);
                DB::table('fiscalizacion_verificaciones')->where('id', $verificacion)->update([...$resultado, 'detalle' => json_encode($resultado['detalle'], JSON_THROW_ON_ERROR)]);
                $this->evento($request, $p, 'consulta_completada', ['verificacion_id' => $verificacion, ...$resultado]);
            });
            $resultados[] = ['id' => $verificacion, ...$resultado];
        }

        return response()->json(['data' => $resultados]);
    }

    public function correoInstitucional(): JsonResponse
    {
        return response()->json(['data' => CorreoFiscalizacion::estado()])->header('Cache-Control', 'private, no-store');
    }

    public function enviarCorreo(Request $request, string $proceso): JsonResponse
    {
        $datos = $request->validate([
            'solo_conciliar' => 'sometimes|boolean', 'clave_envio' => 'required|uuid', 'documento_id' => 'required|uuid', 'entidad_consultada' => 'required|string|max:200',
            'remitente' => 'required|email|max:254', 'destinatario' => 'required|email|max:254',
            'asunto' => ['required', 'string', 'max:250', 'not_regex:/[\r\n]/'], 'cuerpo' => 'required|string|max:10000',
            'fuente' => 'required|in:emisor,sunedu,minedu,rnssc,otro',
        ]);
        $contenido = collect($datos)->except(['clave_envio', 'solo_conciliar'])->map(fn ($valor) => trim($valor))->all();
        $hash = hash('sha256', json_encode($contenido, JSON_THROW_ON_ERROR));
        $reserva = DB::transaction(function () use ($request, $proceso, $datos, $contenido, $hash): array {
            $p = $this->proceso($request, $proceso, true);
            $this->responsable($request, $p);
            $existente = DB::table('fiscalizacion_correos')->where('id', $datos['clave_envio'])->first();
            if ($existente) {
                abort_unless($existente->proceso_id === $p->id && $existente->registrado_por === $request->user()->id && hash_equals($existente->hash_contenido, $hash), 409, 'La referencia de envío ya fue utilizada para otro mensaje.');

                return [$p, $existente];
            }
            abort_if($request->boolean('solo_conciliar'), 404, 'No existe un envío confirmado para completar.');
            $this->editable($p);
            abort_unless(Legajo::query()->whereKey($p->legajo_id)->where('estado_legajo', 'activo')->exists(), 409, 'El legajo está cerrado.');
            $this->documento($request, $p, $datos['documento_id']);
            abort_unless(DB::table('fiscalizacion_documentos')->where('proceso_id', $p->id)->where('documento_id', $datos['documento_id'])->exists(), 422, 'El documento no forma parte del alcance.');
            $conexion = CorreoFiscalizacion::estado();
            abort_unless($conexion['configurado'], 409, 'Revisa la cuenta institucional conectada en Configuración.');
            abort_unless(mb_strtolower((string) $conexion['remitente']) === mb_strtolower($contenido['remitente']), 422, 'El remitente debe ser la cuenta institucional conectada.');
            $duplicado = DB::table('fiscalizacion_correos')->where('proceso_id', $p->id)->where('hash_contenido', $hash)
                ->where(fn ($q) => $q->whereIn('estado', ['en_curso', 'no_confirmado'])->orWhere(fn ($reciente) => $reciente->where('estado', 'enviado')->where('created_at', '>=', now()->subMinute())))->exists();
            abort_if($duplicado, 409, 'Ya existe un envío igual reciente o sin confirmar. Revisa el historial y la carpeta Enviados antes de repetirlo.');
            DB::table('fiscalizacion_correos')->insert([
                'id' => $datos['clave_envio'], 'proceso_id' => $p->id, 'documento_id' => $datos['documento_id'], 'registrado_por' => $request->user()->id,
                'hash_contenido' => $hash, 'estado' => 'en_curso', 'remitente' => $conexion['remitente'], 'destinatario' => $contenido['destinatario'],
                'asunto' => $contenido['asunto'], 'cuerpo' => $contenido['cuerpo'], 'proveedor' => $conexion['proveedor'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->evento($request, $p, 'correo_iniciado', ['envio_id' => $datos['clave_envio'], 'documento_id' => $datos['documento_id'], 'destinatario' => $contenido['destinatario']]);

            return [$p, null];
        });
        [$p, $existente] = $reserva;
        if ($existente) {
            if ($existente->estado === 'enviado') {
                return response()->json(['message' => 'Este correo ya fue enviado y registrado. No se volvió a enviar.', 'data' => $existente]);
            }
            abort_unless($existente->mensaje_id, 409, 'El envío está en curso o no se pudo confirmar. Revisa el historial y la carpeta Enviados; no se reenviará automáticamente.');
        }
        $proveedorId = $existente?->mensaje_id;
        if (! $proveedorId) {
            try {
                $proveedorId = CorreoFiscalizacion::enviar($contenido['remitente'], $contenido['destinatario'], $contenido['asunto'], $contenido['cuerpo']);
            } catch (Throwable $fallo) {
                $rechazado = $fallo instanceof \RuntimeException && $fallo->getCode() >= 400 && $fallo->getCode() < 500 && $fallo->getCode() !== 408;
                DB::transaction(function () use ($request, $p, $datos, $rechazado): void {
                    DB::table('fiscalizacion_correos')->where('id', $datos['clave_envio'])->update(['estado' => $rechazado ? 'error' : 'no_confirmado', 'error' => $rechazado ? 'El proveedor rechazó el envío. Revisa la conexión institucional y el destinatario antes de intentarlo nuevamente.' : 'El proveedor no confirmó el envío. Revisa la cuenta institucional y la carpeta Enviados antes de repetirlo.', 'updated_at' => now()]);
                    $this->evento($request, $p, $rechazado ? 'correo_rechazado' : 'correo_no_confirmado', ['envio_id' => $datos['clave_envio']]);
                });
                abort_if($rechazado, 409, 'El proveedor rechazó el envío. Revisa la conexión institucional y el destinatario. No se registró como enviado.');
                abort(502, 'No se pudo confirmar el envío. Revisa la carpeta Enviados de la cuenta institucional. No se reintentará automáticamente.');
            }
            DB::table('fiscalizacion_correos')->where('id', $datos['clave_envio'])->update(['mensaje_id' => $proveedorId, 'enviado_en' => now(), 'updated_at' => now()]);
        }
        $envio = DB::transaction(function () use ($request, $p, $datos, $contenido, $proveedorId): object {
            $p = ProcesoFiscalizacion::query()->lockForUpdate()->findOrFail($p->id);
            $actual = DB::table('fiscalizacion_correos')->where('id', $datos['clave_envio'])->lockForUpdate()->first();
            if ($actual->solicitud_id) {
                return $actual;
            }
            $s = SolicitudFiscalizacion::query()->create([
                'proceso_fiscalizacion_id' => $p->id, 'documento_id' => $datos['documento_id'], 'entidad_consultada' => $contenido['entidad_consultada'],
                'medio' => 'correo', 'fecha_envio' => Carbon::parse($actual->enviado_en)->timezone('America/Lima')->toDateString(), 'resultado' => 'pendiente', 'registrado_por' => $request->user()->id,
                'datos_medio' => ['remitente' => $contenido['remitente'], 'destinatario' => $contenido['destinatario'], 'asunto' => $contenido['asunto'], 'cuerpo' => $contenido['cuerpo'], 'mensaje_id' => $proveedorId, 'envio_id' => $datos['clave_envio']],
            ]);
            DB::table('fiscalizacion_correos')->where('id', $datos['clave_envio'])->update(['estado' => 'enviado', 'solicitud_id' => $s->id, 'updated_at' => now()]);
            if (in_array($p->estado, ['abierto', 'en_verificacion'], true)) {
                $p->update(['estado' => 'en_verificacion']);
            }
            $this->evento($request, $p, 'correo_enviado', ['envio_id' => $datos['clave_envio'], 'solicitud_id' => $s->id, 'mensaje_id' => $proveedorId, ...$contenido]);

            return DB::table('fiscalizacion_correos')->where('id', $datos['clave_envio'])->first();
        });

        return response()->json(['message' => 'El proveedor confirmó el envío y se registró la consulta. Esto no confirma su lectura ni recepción por el destinatario.', 'data' => $envio], 201);
    }

    public function reconocerConsulta(Request $request, string $proceso, ReconocimientoTitulo $lector, ReconocimientoConsulta $analizador): JsonResponse
    {
        $data = $request->validate(['documento_id' => 'required|uuid', 'evidencia_documento_id' => 'required|uuid|different:documento_id']);
        $p = $this->proceso($request, $proceso);
        $this->editable($p);
        $this->responsable($request, $p);
        $this->documento($request, $p, $data['documento_id']);
        abort_unless(DB::table('fiscalizacion_documentos')->where('proceso_id', $p->id)->where('documento_id', $data['documento_id'])->exists(), 422, 'El documento no forma parte del alcance.');
        $evidencia = $this->documento($request, $p, $data['evidencia_documento_id']);
        $entrada = Storage::disk(config('legajo.disco_documentos'))->readStream($evidencia->archivo->storage_path);
        abort_unless(is_resource($entrada), 422, 'No se pudo abrir la constancia seleccionada.');
        $temporal = tempnam(sys_get_temp_dir(), 'consulta-');
        if ($temporal === false) {
            fclose($entrada);
            abort(503, 'No se pudo preparar la lectura.');
        }
        try {
            $salida = fopen($temporal, 'wb');
            if (! is_resource($salida)) {
                fclose($entrada);
                abort(503, 'No se pudo preparar la lectura.');
            }
            try {
                $maximo = Parametros::entero('tamano_maximo_pdf_kb') * 1024;
                $bytes = stream_copy_to_stream($entrada, $salida, $maximo + 1);
                abort_if($bytes === false || $bytes > $maximo, 422, 'La constancia supera el tamaño permitido para reconocimiento.');
            } finally {
                fclose($entrada);
                fclose($salida);
            }
            $lectura = $lector->extraer(new UploadedFile($temporal, 'constancia.pdf', null, null, true));

            return response()->json(['data' => [...$lectura, ...$analizador->analizar($lectura['texto']), 'evidencia_documento_id' => $evidencia->id]]);
        } finally {
            @unlink($temporal);
        }
    }

    public function consultaEmisor(Request $request, string $proceso): JsonResponse
    {
        $reglas = ['documento_id' => 'required|uuid', 'entidad_consultada' => 'required|string|max:200', 'medio' => 'required|in:oficio,correo,registro_oficial',
            'numero_oficio' => 'required_if:medio,oficio|nullable|string|max:40', 'fecha_envio' => 'required|date_format:Y-m-d|before_or_equal:'.now('America/Lima')->toDateString(),
            'evidencia_documento_id' => 'required|uuid', 'fuente' => 'required|in:emisor,sunedu,minedu,rnssc,otro'];
        if ($request->has('datos_medio')) {
            $camposMedio = match ($request->input('medio')) {
                'correo' => ['remitente' => 'required|email|max:254', 'destinatario' => 'required|email|max:254', 'asunto' => 'required|string|max:250'],
                'oficio' => ['destinatario' => 'required|string|max:200', 'cargo_destinatario' => 'nullable|string|max:150',
                    'canal_envio' => 'required|in:mesa_partes_fisica,mesa_partes_virtual,correo,otro', 'expediente' => 'nullable|string|max:80'],
                'registro_oficial' => ['nombre_registro' => 'required|string|max:200', 'url_registro' => 'required|url:http,https|max:1000',
                    'criterio_busqueda' => 'required|string|max:250', 'resultado_consulta' => 'required|string|max:2000'],
                default => [],
            };
            $reglas['datos_medio'] = 'required|array:'.implode(',', array_keys($camposMedio));
            foreach ($camposMedio as $campo => $regla) {
                $reglas['datos_medio.'.$campo] = $regla;
            }
        }
        $data = $request->validate($reglas);
        $solicitud = DB::transaction(function () use ($request, $proceso, $data): SolicitudFiscalizacion {
            $p = $this->proceso($request, $proceso, true);
            $this->editable($p);
            $this->responsable($request, $p);
            $this->documento($request, $p, $data['documento_id']);
            abort_unless(DB::table('fiscalizacion_documentos')->where('proceso_id', $p->id)->where('documento_id', $data['documento_id'])->exists(), 422, 'El documento no forma parte del alcance.');
            $evidencia = $this->documento($request, $p, $data['evidencia_documento_id']);
            abort_if($evidencia->id === $data['documento_id'], 422, 'Adjunta una constancia distinta del documento consultado.');
            abort_if($data['fecha_envio'] < $p->fecha_inicio->format('Y-m-d'), 422, 'El envío no puede ser anterior al inicio del proceso.');
            $solicitud = SolicitudFiscalizacion::query()->create([...collect($data)->except(['evidencia_documento_id', 'fuente'])->all(),
                'proceso_fiscalizacion_id' => $p->id, 'registrado_por' => $request->user()->id, 'oficio_archivo_id' => $evidencia->archivo_id, 'resultado' => 'pendiente']);
            $p->update(['estado' => 'en_verificacion']);
            $this->evento($request, $p, 'consulta_emisor', ['solicitud_id' => $solicitud->id, ...$data]);

            return $solicitud;
        });

        return response()->json(['data' => $solicitud], 201);
    }

    public function respuesta(Request $request, string $proceso, string $solicitud): JsonResponse
    {
        $data = $request->validate(['fecha_respuesta' => 'required|date_format:Y-m-d|before_or_equal:'.now('America/Lima')->toDateString(), 'documento_respuesta_id' => 'required|uuid',
            'resultado' => ['required', Rule::in(self::RESULTADOS)], 'descripcion_hallazgo' => 'required|string|max:5000']);
        DB::transaction(function () use ($request, $proceso, $solicitud, $data): void {
            $p = $this->proceso($request, $proceso, true);
            $this->editable($p);
            $this->responsable($request, $p);
            $s = $p->solicitudesFiscalizacion()->findOrFail($solicitud);
            abort_unless($s->resultado === 'pendiente', 409, 'La respuesta se conserva. Registra una nueva actuación para aclararla.');
            $evidencia = $this->documento($request, $p, $data['documento_respuesta_id']);
            abort_if($evidencia->id === $s->documento_id || $data['fecha_respuesta'] < $s->fecha_envio->format('Y-m-d'), 422, 'Revisa el documento de respuesta y la fecha.');
            $s->update($data);
            $this->evento($request, $p, 'respuesta_emisor', ['solicitud_id' => $s->id, ...$data]);
        });

        return response()->json(['message' => 'Respuesta registrada con sustento.']);
    }

    public function evaluar(Request $request, string $proceso, string $alcance): JsonResponse
    {
        $data = $request->validate(['resultado' => ['required', Rule::in(self::RESULTADOS)], 'conclusion' => 'required|string|max:5000',
            'evidencia_documento_id' => 'required|uuid']);
        DB::transaction(function () use ($request, $proceso, $alcance, $data): void {
            $p = $this->proceso($request, $proceso, true);
            $this->editable($p);
            $this->responsable($request, $p);
            $fila = DB::table('fiscalizacion_documentos')->where('proceso_id', $p->id)->where('id', $alcance)->first();
            abort_unless($fila, 404);
            $this->documento($request, $p, $fila->documento_id);
            $evidencia = $this->documento($request, $p, $data['evidencia_documento_id']);
            abort_if($evidencia->id === $fila->documento_id, 422, 'La evaluación requiere evidencia distinta del documento evaluado.');
            DB::table('fiscalizacion_documentos')->where('id', $fila->id)->update([...$data, 'evaluado_por' => $request->user()->id, 'evaluado_en' => now(), 'updated_at' => now()]);
            $this->evento($request, $p, 'evaluacion_documento', ['alcance_id' => $fila->id, 'antes' => ['resultado' => $fila->resultado, 'conclusion' => $fila->conclusion], ...$data]);
        });

        return response()->json(['message' => 'Evaluación guardada. Se hará efectiva en el legajo al cerrar el proceso.']);
    }

    public function presentar(Request $request, string $proceso): JsonResponse
    {
        $request->mergeIfMissing(['modalidad' => $request->filled('revisor_id') ? 'delegada' : 'individual']);
        $data = $request->validate([
            'modalidad' => 'required|in:individual,delegada',
            'revisor_id' => 'prohibited_if:modalidad,individual|required_if:modalidad,delegada|uuid|exists:usuarios,id',
            'confirmacion' => 'exclude_unless:modalidad,individual|required|accepted',
            'informe_documento_id' => 'required|uuid', 'conclusiones' => 'required|string|max:15000',
        ]);
        DB::transaction(function () use ($request, $proceso, $data): void {
            $p = $this->proceso($request, $proceso, true);
            $this->editable($p);
            $this->responsable($request, $p);
            $this->documento($request, $p, $data['informe_documento_id']);
            abort_if(DB::table('fiscalizacion_documentos')->where('proceso_id', $p->id)->where('documento_id', $data['informe_documento_id'])->exists(), 422, 'El informe debe ser distinto de los documentos evaluados.');
            $this->completo($p);
            $informe = ['informe_documento_id' => $data['informe_documento_id'], 'conclusiones' => $data['conclusiones'], 'presentado_en' => now()];
            if ($data['modalidad'] === 'individual') {
                $p->update([...$informe, 'revisor_id' => null]);
                $this->cerrar($request, $p, 'Revisión y cierre realizados por el responsable, con confirmación expresa de las evidencias y conclusiones.');
                $this->evento($request, $p, 'cierre_responsable', [...$informe, 'modalidad' => 'individual', 'confirmacion' => true]);

                return;
            }
            $revisor = Usuario::query()->findOrFail($data['revisor_id']);
            abort_unless($revisor->activo && $revisor->can('fiscalizacion.aprobar') && $revisor->id !== $p->responsable_id, 422, 'Selecciona un revisor autorizado diferente del responsable.');
            $revisionRequest = clone $request;
            $revisionRequest->setUserResolver(fn () => $revisor);
            $this->documento($revisionRequest, $p, $data['informe_documento_id']);
            abort_unless($this->consultaVisible($revisionRequest)->whereKey($p->id)->exists(), 422, 'El revisor no tiene acceso a todos los documentos del proceso.');
            $p->update([...$informe, 'revisor_id' => $revisor->id, 'estado' => 'en_revision', 'revision_observaciones' => null]);
            $this->evento($request, $p, 'informe_presentado', ['modalidad' => 'delegada', ...$data]);
        });

        return response()->json(['message' => $data['modalidad'] === 'individual' ? 'Proceso revisado y cerrado por el responsable. Se conservaron el informe y las evidencias.' : 'Informe enviado a revisión.']);
    }

    public function revisar(Request $request, string $proceso): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|in:aprobar,devolver', 'observaciones' => 'required|string|max:5000']);
        DB::transaction(function () use ($request, $proceso, $data): void {
            $p = $this->proceso($request, $proceso, true);
            abort_unless($p->estado === 'en_revision' && $p->revisor_id === $request->user()->id && $p->responsable_id !== $request->user()->id, 403, 'Solo el revisor asignado puede resolver este informe.');
            $this->completo($p);
            if ($data['decision'] === 'aprobar') {
                $this->cerrar($request, $p, $data['observaciones']);
            } else {
                $p->update(['estado' => 'en_verificacion', 'revisado_en' => now(), 'revision_observaciones' => $data['observaciones']]);
            }
            $this->evento($request, $p, 'revision_'.$data['decision'], $data);
        });

        return response()->json(['message' => $data['decision'] === 'aprobar' ? 'Cierre aprobado. Se conservan el informe y las evidencias.' : 'Informe devuelto al responsable con observaciones.']);
    }

    public function derivar(Request $request, string $proceso): JsonResponse
    {
        $data = $request->validate(['area' => 'required|string|max:150', 'motivo' => 'required|string|max:3000', 'referencia' => 'required|string|max:150',
            'documento_id' => 'required|uuid', 'fecha_envio' => 'required|date_format:Y-m-d|before_or_equal:'.now('America/Lima')->toDateString()]);
        DB::transaction(function () use ($request, $proceso, $data): void {
            $p = $this->proceso($request, $proceso, true);
            $this->responsable($request, $p);
            abort_unless($p->estado === 'cerrado', 409, 'Aprueba el informe antes de registrar la derivación.');
            $this->documento($request, $p, $data['documento_id']);
            abort_if($data['fecha_envio'] < $p->fecha_fin->format('Y-m-d'), 422, 'La derivación no puede ser anterior al cierre.');
            $id = (string) Str::uuid();
            DB::table('fiscalizacion_derivaciones')->insert(['id' => $id, 'proceso_id' => $p->id, ...$data, 'registrado_por' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->evento($request, $p, 'derivacion_registrada', ['id' => $id, ...$data]);
        });

        return response()->json(['message' => 'Derivación registrada.'], 201);
    }

    public function cargo(Request $request, string $proceso, string $derivacion): JsonResponse
    {
        $data = $request->validate(['fecha_cargo' => 'required|date_format:Y-m-d|before_or_equal:'.now('America/Lima')->toDateString(), 'documento_id' => 'required|uuid']);
        DB::transaction(function () use ($request, $proceso, $derivacion, $data): void {
            $p = $this->proceso($request, $proceso, true);
            $this->responsable($request, $p);
            $fila = DB::table('fiscalizacion_derivaciones')->where('proceso_id', $p->id)->where('id', $derivacion)->first();
            abort_unless($fila, 404);
            abort_if($fila->fecha_cargo || $data['fecha_cargo'] < $fila->fecha_envio, 422, 'Revisa la fecha de cargo; un cargo registrado se conserva.');
            $this->documento($request, $p, $data['documento_id']);
            DB::table('fiscalizacion_derivaciones')->where('id', $fila->id)->update(['fecha_cargo' => $data['fecha_cargo'], 'cargo_documento_id' => $data['documento_id'], 'updated_at' => now()]);
            $this->evento($request, $p, 'cargo_registrado', ['derivacion_id' => $fila->id, ...$data]);
        });

        return response()->json(['message' => 'Cargo registrado con evidencia.']);
    }

    public function consultaVisible(Request $request): Builder
    {
        if (! $request->user()->can('fiscalizacion.ver')) {
            return ProcesoFiscalizacion::query()->whereRaw('1 = 0');
        }
        $ocultos = DocumentoUsuario::query()->whereNotIn('id', $this->documentosVisibles($request)->select('documentos_usuario.id'))->select('id');

        return ProcesoFiscalizacion::query()
            ->whereNotIn('id', DB::table('fiscalizacion_documentos')->whereIn('documento_id', clone $ocultos)->select('proceso_id'))
            ->whereNotIn('id', SolicitudFiscalizacion::query()->whereIn('documento_id', clone $ocultos)->orWhereIn('documento_respuesta_id', clone $ocultos)->select('proceso_fiscalizacion_id'))
            ->where(fn (Builder $q) => $q->whereNull('informe_documento_id')->orWhereNotIn('informe_documento_id', clone $ocultos))
            ->whereNotIn('id', SolicitudFiscalizacion::query()->whereIn('oficio_archivo_id', DocumentoUsuario::query()->whereIn('id', clone $ocultos)->whereNotNull('archivo_id')->select('archivo_id'))->select('proceso_fiscalizacion_id'))
            ->whereNotIn('id', DB::table('fiscalizacion_documentos')->whereIn('evidencia_documento_id', clone $ocultos)->select('proceso_id'))
            ->whereNotIn('id', DB::table('fiscalizacion_derivaciones')->whereIn('documento_id', clone $ocultos)->orWhereIn('cargo_documento_id', clone $ocultos)->select('proceso_id'));
    }

    private function documentosVisibles(Request $request): Builder
    {
        return DocumentoUsuario::query()->with(['archivo', 'tipoDocumento', 'formacionAcademica', 'experienciaLaboral'])
            ->when(! $request->user()->veDatosSensibles(), fn (Builder $q) => $q->whereNotIn('seccion_legajo_id', [1, 9, 10])->whereHas('tipoDocumento', fn (Builder $t) => $t->where('es_dato_sensible', false)->where('es_dato_salud', false)))
            ->when(! $request->user()->can('seccion08.sanciones.ver'), fn (Builder $q) => $q->where('seccion_legajo_id', '!=', 8));
    }

    private function proceso(Request $request, string $id, bool $lock = false): ProcesoFiscalizacion
    {
        return $this->consultaVisible($request)->when($lock, fn (Builder $q) => $q->lockForUpdate())->findOrFail($id);
    }

    private function documento(Request $request, ProcesoFiscalizacion $p, string $id): DocumentoUsuario
    {
        $doc = $this->documentosVisibles($request)->where('usuario_id', $p->usuario_id)->where('legajo_id', $p->legajo_id)->with('archivo')->findOrFail($id);
        abort_if(! $doc->archivo || $doc->archivo->estado_antivirus === 'infectado' || in_array($doc->estado, ['anulado', 'falso'], true), 422, 'Selecciona un documento disponible con archivo no bloqueado.');

        $disco = (string) config('legajo.disco_documentos');
        abort_unless($doc->archivo->almacenamiento === $disco && Storage::disk($disco)->exists($doc->archivo->storage_path), 422, 'El archivo debe estar disponible en el almacenamiento institucional.');

        return $doc;
    }

    private function editable(ProcesoFiscalizacion $p): void
    {
        abort_unless(in_array($p->estado, ['abierto', 'en_verificacion'], true), 409, 'El proceso está en revisión o cerrado. Se conserva sin modificaciones.');
    }

    private function responsable(Request $request, ProcesoFiscalizacion $p): void
    {
        abort_unless($p->responsable_id === $request->user()->id, 403, 'Esta actuación corresponde al responsable asignado.');
    }

    private function incorporar(Request $request, ProcesoFiscalizacion $p, string $id): void
    {
        $this->responsable($request, $p);
        $doc = $this->documento($request, $p, $id);
        abort_if(DB::table('fiscalizacion_documentos')->where('proceso_id', $p->id)->where('documento_id', $id)->exists(), 422, 'El documento ya forma parte del alcance.');
        DB::table('fiscalizacion_documentos')->insert(['id' => (string) Str::uuid(), 'proceso_id' => $p->id, 'documento_id' => $id,
            'archivo_id' => $doc->archivo_id, 'hash_sha256' => $doc->archivo->hash_sha256,
            'datos_snapshot' => json_encode($this->serializarDocumento($doc), JSON_THROW_ON_ERROR), 'resultado' => 'pendiente', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function serializarDocumento(DocumentoUsuario $doc): array
    {
        return [...$doc->only(['id', 'nombre', 'num_resolucion', 'seccion_legajo_id', 'folio_inicio', 'folio_fin', 'emisor', 'fecha_emision', 'estado', 'fiscalizado', 'fecha_fiscalizacion', 'resultado_fiscalizacion', 'proceso_fiscalizacion_id']),
            'formacion' => $doc->seccion_legajo_id === 3 ? $doc->formacionAcademica->where('anulado', false)->map(fn ($f) => $f->only(['nivel', 'denominacion', 'institucion', 'pais', 'fecha_obtencion', 'numero_registro_sunedu', 'numero_diploma']))->values()->all() : [],
            'experiencia' => $doc->seccion_legajo_id === 4 ? $doc->experienciaLaboral->where('anulado', false)->map(fn ($e) => $e->only(['entidad_empresa', 'cargo', 'fecha_inicio', 'fecha_fin', 'funciones']))->values()->all() : [],
            'tipo' => $doc->tipoDocumento?->nombre, 'archivo' => $doc->archivo?->only(['id', 'nombre_original', 'hash_sha256', 'estado_antivirus'])];
    }

    private function cerrar(Request $request, ProcesoFiscalizacion $p, string $observaciones): void
    {
        $this->completo($p);
        $this->documento($request, $p, $p->informe_documento_id);
        foreach (DB::table('fiscalizacion_documentos')->where('proceso_id', $p->id)->orderBy('documento_id')->get() as $fila) {
            $doc = $this->documento($request, $p, $fila->documento_id);
            abort_unless($doc->archivo_id === $fila->archivo_id && $doc->archivo?->hash_sha256 === $fila->hash_sha256, 409, 'Un documento cambió respecto al alcance. Revisa el archivo antes de cerrar.');
            $integridad = VerificacionFiscalizacion::ejecutar($doc, 'integridad', $fila->hash_sha256);
            abort_unless($integridad['resultado_fuente'] === 'integridad_coincide', 409, 'No se pudo confirmar la integridad de un archivo. Revisa el documento antes de cerrar.');
            $antes = $doc->only(['fiscalizado', 'fecha_fiscalizacion', 'fiscalizado_por', 'resultado_fiscalizacion', 'proceso_fiscalizacion_id']);
            $doc->update(['resultado_fiscalizacion' => $fila->resultado, 'proceso_fiscalizacion_id' => $p->id, 'fiscalizado' => $fila->resultado === 'conforme', 'fecha_fiscalizacion' => now()->toDateString(), 'fiscalizado_por' => $request->user()->id]);
            AuditoriaDocumento::registrar($doc, $request, 'fiscalizacion.conclusion_aprobada', $antes, ['proceso_id' => $p->id, 'resultado' => $fila->resultado, 'conclusion' => $fila->conclusion]);
        }
        $p->update(['estado' => 'cerrado', 'fecha_fin' => now('America/Lima')->toDateString(), 'revisado_en' => now(), 'revision_observaciones' => $observaciones]);
    }

    private function completo(ProcesoFiscalizacion $p): void
    {
        abort_if(DB::table('fiscalizacion_correos')->where('proceso_id', $p->id)->where('estado', 'en_curso')->exists(), 409, 'Hay un envío de correo en curso o pendiente de conciliación. Comprueba su estado antes de finalizar.');
        $alcance = DB::table('fiscalizacion_documentos')->where('proceso_id', $p->id);
        if (! (clone $alcance)->exists() || (clone $alcance)->where('resultado', 'pendiente')->exists()) {
            throw ValidationException::withMessages(['alcance' => 'Evalúa todos los documentos incluidos antes de presentar el informe o cerrar el proceso.']);
        }
        $interrumpidas = DB::table('fiscalizacion_verificaciones')->whereIn('alcance_id', (clone $alcance)->select('id'))->where('estado_tecnico', 'en_curso')->where('created_at', '<', now()->subMinutes(5))->pluck('id');
        if ($interrumpidas->isNotEmpty()) {
            DB::table('fiscalizacion_verificaciones')->whereIn('id', $interrumpidas)->update(['estado_tecnico' => 'no_disponible', 'resultado_fuente' => 'consulta_interrumpida']);
            $this->evento(request(), $p, 'consultas_interrumpidas', ['verificaciones' => $interrumpidas->all()]);
        }
        abort_if(DB::table('fiscalizacion_verificaciones')->whereIn('alcance_id', (clone $alcance)->select('id'))->where('estado_tecnico', 'en_curso')->exists(), 409, 'Hay verificaciones en curso. Espera su finalización.');
        $pendientes = $p->solicitudesFiscalizacion()->where('resultado', 'pendiente')->pluck('documento_id');
        abort_if((clone $alcance)->whereIn('documento_id', $pendientes)->where('resultado', '!=', 'no_concluyente')->exists(), 422, 'Resuelve las consultas pendientes o sustenta una evaluación no concluyente.');
    }

    private function evento(Request $request, ProcesoFiscalizacion $p, string $accion, array $detalle): void
    {
        Auditoria::registrar($request, 'fiscalizacion.'.$accion, 'procesos_fiscalizacion', $p->id, $p->usuario_id, null, $detalle);
    }
}
