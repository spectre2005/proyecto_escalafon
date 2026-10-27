<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\NotificacionUsuario;
use App\Models\RequerimientoDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\ConsultaReportes;
use App\Support\CorreoRequerimiento;
use App\Support\Documentos\ArchivoPdf;
use App\Support\GmailCorreo;
use App\Support\MensajeRequerimiento;
use App\Support\Parametros;
use App\Support\RequerimientosAutomaticos;
use App\Support\ResumenSeccion03;
use App\Support\ResumenSeccion04;
use App\Support\ResumenSeccion06;
use App\Support\ResumenSeccion08;
use App\Support\ResumenSeccion10;
use App\Support\Seccion05;
use App\Support\Seccion09;
use App\Support\Seccion11;
use Database\Seeders\Seccion01CatalogoSeeder;
use Database\Seeders\Seccion02CatalogoSeeder;
use Database\Seeders\Seccion07CatalogoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SolicitudLegajoController extends Controller
{
    private const ABIERTOS = ['abierto', 'observado', 'en_revision'];

    private function consulta(Request $request): Builder
    {
        return RequerimientoDocumento::query()
            ->when(! $request->user()->veDatosSensibles(), fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('tipo_documento_id')->orWhereHas('tipoDocumento', fn (Builder $t) => $t->where('es_dato_sensible', false)->where('es_dato_salud', false)->whereNotIn('seccion_legajo_id', [1, 9, 10]))));
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['clase' => 'nullable|in:documentos,actualizacion', 'estado' => 'nullable|in:abierto,observado,en_revision,aceptado,cancelado', 'q' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1']);
        $q = $this->consulta($request)->with(['usuario:id,nombres,apellido_paterno,apellido_materno', 'tipoDocumento:id,nombre,codigo,seccion_legajo_id,subseccion_legajo_id', 'tipoDocumento.subseccionLegajo:id,codigo', 'documento:id,nombre,estado', 'notificacionesUsuario' => fn ($q) => $q->whereNotNull('correo_estado')->orderByDesc('created_at')->limit(1)]);
        foreach (['clase', 'estado'] as $campo) {
            if (! empty($data[$campo])) {
                $q->where($campo, $data[$campo]);
            }
        }
        if (! empty($data['q'])) {
            $term = '%'.mb_strtolower(trim($data['q'])).'%';
            $q->where(fn (Builder $q) => $q->whereRaw('LOWER(descripcion) LIKE ?', [$term])->orWhereHas('usuario', fn (Builder $u) => $u->whereRaw('LOWER(nombres) LIKE ?', [$term])->orWhereRaw('LOWER(apellido_paterno) LIKE ?', [$term])->orWhereRaw('LOWER(apellido_materno) LIKE ?', [$term])->orWhere('numero_doc_identidad', 'like', $term)));
        }
        $pagina = $q->orderByDesc('created_at')->orderBy('id')->paginate(20)->withQueryString();
        $pagina->getCollection()->transform(function (RequerimientoDocumento $r): array {
            $datos = $r->toArray();
            $datos['seguimiento'] = array_map(function (array $evento): array {
                unset($evento['ruta'], $evento['disco']);
                if (isset($evento['archivos'])) {
                    $evento['archivos'] = array_map(function (array $archivo): array {
                        unset($archivo['ruta'], $archivo['disco']);

                        return $archivo;
                    }, $evento['archivos']);
                }

                return $evento;
            }, $r->seguimiento ?? []);
            $datos['correo'] = $r->notificacionesUsuario->first()?->only(['id', 'correo_estado', 'correo_destinatario', 'correo_enviado_en', 'correo_error']);
            unset($datos['notificaciones_usuario']);
            $datos['atrasado'] = in_array($r->estado, ['abierto', 'observado'], true) && ($r->prorroga_hasta ?? $r->fecha_limite)->toDateString() < now('America/Lima')->toDateString();

            return $datos;
        });

        return response()->json([...$pagina->toArray(), 'correo_configurado' => CorreoRequerimiento::configurado(),
            'recepcion_automatica' => GmailCorreo::recepcionLista(), 'avisos' => NotificacionUsuario::query()
                ->where('usuario_id', $request->user()->id)->whereIn('requerimiento_id', $this->consulta($request)->select('id'))
                ->where('tipo', 'solicitud_legajo')->orderByDesc('created_at')->limit(5)->get(['id', 'titulo', 'mensaje', 'created_at', 'requerimiento_id'])]);
    }

    public function sincronizar(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('sistema.administrar'), 403);

        return response()->json(['creados' => RequerimientosAutomaticos::sincronizar($request->user()),
            'plazo_dias' => max(1, Parametros::entero('requerimiento_plazo_dias_calendario'))]);
    }

    private function tiposDisponibles(): Builder
    {
        $catalogos = [
            1 => array_column(Seccion01CatalogoSeeder::TIPOS, 0),
            2 => array_column(Seccion02CatalogoSeeder::TIPOS, 1),
            3 => array_keys(ResumenSeccion03::ITEMS),
            4 => array_keys(ResumenSeccion04::ITEMS),
            5 => array_keys(Seccion05::TIPOS_DOCUMENTO),
            6 => array_keys(ResumenSeccion06::ITEMS),
            7 => array_column(Seccion07CatalogoSeeder::TIPOS, 0),
            8 => array_keys(ResumenSeccion08::ITEMS),
            9 => array_keys(Seccion09::TIPOS_DOCUMENTO),
            10 => array_keys(ResumenSeccion10::TIPOS),
            11 => array_keys(Seccion11::TIPOS_DOCUMENTO),
        ];

        return TipoDocumento::query()->where('activo', true)->where(function (Builder $query) use ($catalogos): void {
            foreach ($catalogos as $seccion => $codigos) {
                $query->orWhere(fn (Builder $q) => $q->where('seccion_legajo_id', $seccion)->whereIn('codigo', $codigos));
            }
        });
    }

    public function opciones(Request $request): JsonResponse
    {
        $admin = $request->user()->can('requerimientos.gestionar');
        $personas = Usuario::query()->whereHas('legajos', fn (Builder $q) => $q->where('estado_legajo', 'activo'))
            ->when(! $admin, fn (Builder $q) => $q->whereKey($request->user()->id))->orderBy('apellido_paterno')->get(['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'numero_doc_identidad', 'email_institucional']);
        $tipos = $this->tiposDisponibles()
            ->when(! $request->user()->veDatosSensibles(), fn (Builder $q) => $q->where('es_dato_sensible', false)->where('es_dato_salud', false)->whereNotIn('seccion_legajo_id', [1, 9, 10]))
            ->orderBy('nombre')->get(['id', 'nombre']);

        return response()->json(['personas' => $personas, 'tipos' => $tipos, 'maximo_pdf_kb' => Parametros::entero('tamano_maximo_pdf_kb')]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('requerimientos.gestionar'), 403);
        $data = $request->validate([
            'clase' => 'required|in:documentos,actualizacion', 'usuario_id' => 'required|uuid|exists:usuarios,id',
            'descripcion' => 'required|string|max:300', 'tipo_documento_id' => 'nullable|uuid|exists:tipos_documento,id',
            'tipo_documento_ids' => 'sometimes|array|max:20',
            'tipo_documento_ids.*' => 'required|uuid|distinct|exists:tipos_documento,id',
            'fecha_limite' => 'required|date_format:Y-m-d|after_or_equal:'.now('America/Lima')->toDateString(),
        ]);
        $tipos = $data['tipo_documento_ids'] ?? (! empty($data['tipo_documento_id']) ? [$data['tipo_documento_id']] : []);
        foreach ($tipos as $tipo) {
            $this->tipoVisible($request, $tipo);
        }
        unset($data['tipo_documento_ids'], $data['tipo_documento_id']);
        $notificaciones = [];
        $registros = DB::transaction(function () use ($data, $tipos, $request, &$notificaciones): array {
            Usuario::query()->whereKey($data['usuario_id'])->lockForUpdate()->firstOrFail();
            $this->legajoActivo($data['usuario_id']);
            $registros = [];
            foreach ($tipos ?: [null] as $tipo) {
                $duplicado = RequerimientoDocumento::query()->where('usuario_id', $data['usuario_id'])->where('clase', $data['clase'])->whereIn('estado', self::ABIERTOS);
                if ($tipo) {
                    $duplicado->where('tipo_documento_id', $tipo);
                } else {
                    $duplicado->whereNull('tipo_documento_id')->where('descripcion', $data['descripcion']);
                }
                $existente = $duplicado->lockForUpdate()->first();
                abort_if($existente && ($existente->origen !== 'automatico' || $existente->estado === 'en_revision'), 409, 'Ya existe un requerimiento abierto para uno de los documentos. Revísalo desde Ver y gestionar.');
                $nombreTipo = $tipo ? TipoDocumento::query()->whereKey($tipo)->value('nombre') : null;
                $descripcion = mb_substr(($nombreTipo ? $nombreTipo.': ' : '').$data['descripcion'], 0, 300);
                $valores = [...$data, 'tipo_documento_id' => $tipo, 'descripcion' => $descripcion, 'origen' => 'legajo',
                    'fecha_requerimiento' => now('America/Lima')->toDateString(), 'plazo_dias_habiles' => 0,
                    'estado' => 'abierto', 'solicitado_por' => $request->user()->id, 'prorroga_hasta' => null];
                if ($existente) {
                    $existente->update($valores);
                    $r = $existente;
                } else {
                    $r = RequerimientoDocumento::query()->create($valores);
                }
                $this->evento($r, $request, 'creada por administración', $descripcion);
                $notificaciones[] = $this->notificar($r, MensajeRequerimiento::TITULO_SOLICITUD, $descripcion)->id;
                $registros[] = $r;
            }

            return $registros;
        });

        return response()->json(['data' => $request->has('tipo_documento_ids') ? $registros : $registros[0],
            'correos' => NotificacionUsuario::query()->whereIn('id', $notificaciones)->get(['id', 'requerimiento_id', 'correo_estado', 'correo_destinatario', 'correo_error']),
            'enviados' => 0, 'total' => count($notificaciones)], 201);
    }

    public function enviar(Request $request, string $record): JsonResponse
    {
        abort_unless($request->user()->can('requerimientos.gestionar'), 403);
        $data = $request->validate(['notificacion_id' => 'required|uuid']);
        $r = $this->consulta($request)->findOrFail($record);
        $n = $r->notificacionesUsuario()->whereNotNull('correo_estado')->findOrFail($data['notificacion_id']);
        CorreoRequerimiento::procesar([$n->id]);

        return response()->json(['correo' => $n->fresh()->only(['id', 'correo_estado', 'correo_destinatario', 'correo_error'])]);
    }

    public function registrarEntrega(Request $request, string $record): JsonResponse
    {
        abort_unless($request->user()->can('requerimientos.gestionar'), 403);
        $data = $request->validate(['remitente' => 'required|email|max:255', 'referencia_correo' => 'required|string|max:300', 'mensaje' => 'required|string|max:3000', 'pdf' => 'nullable|file|max:'.Parametros::entero('tamano_maximo_pdf_kb')]);
        $pdf = $request->file('pdf');
        if ($pdf && ! ArchivoPdf::esPdf($pdf)) {
            throw ValidationException::withMessages(['pdf' => 'El archivo debe ser un PDF válido.']);
        }
        $ruta = null;
        $disco = (string) config('legajo.disco_documentos');
        try {
            $r = DB::transaction(function () use ($request, $record, $data, $pdf, $disco, &$ruta): RequerimientoDocumento {
                $r = $this->consulta($request)->lockForUpdate()->findOrFail($record);
                $correoTitular = $r->usuario()->value('email_institucional');
                if (mb_strtolower(trim($data['remitente'])) !== mb_strtolower(trim((string) $correoTitular))) {
                    throw ValidationException::withMessages(['remitente' => 'El remitente no coincide con el correo registrado del titular. Verifica su identidad y corrige su correo en Personal si corresponde.']);
                }
                $this->legajoActivo($r->usuario_id);
                abort_unless(in_array($r->estado, ['abierto', 'observado'], true), 409, 'La solicitud ya fue enviada a revisión o está cerrada.');
                $extra = ['canal' => 'correo_manual', 'remitente' => $data['remitente'], 'referencia_correo' => $data['referencia_correo']];
                if ($pdf) {
                    $ruta = $pdf->store('solicitudes/'.$r->id, $disco);
                    abort_unless($ruta, 500, 'No se pudo guardar el archivo.');
                    $extra = ['canal' => 'correo_manual', 'remitente' => $data['remitente'], 'referencia_correo' => $data['referencia_correo'], 'ruta' => $ruta, 'disco' => $disco, 'archivo' => mb_substr($pdf->getClientOriginalName(), 0, 255), 'hash' => ArchivoPdf::hash($pdf)];
                }
                $this->evento($r, $request, 'respuesta', $data['mensaje'], $extra);
                $r->update(['estado' => 'en_revision']);

                return $r;
            });
        } catch (Throwable $e) {
            if ($ruta) {
                Storage::disk($disco)->delete($ruta);
            }
            throw $e;
        }

        return response()->json(['data' => ['id' => $r->id, 'estado' => $r->estado]]);
    }

    public function gestionar(Request $request, string $record): JsonResponse
    {
        abort_unless($request->user()->can('requerimientos.gestionar'), 403);
        $data = $request->validate(['accion' => 'required|in:observar,aceptar,cancelar,prorrogar,recordar', 'mensaje' => 'required|string|max:3000',
            'prorroga_hasta' => 'required_if:accion,prorrogar|nullable|date_format:Y-m-d|after_or_equal:'.now('America/Lima')->toDateString(), 'documento_id' => 'nullable|uuid|exists:documentos_usuario,id']);
        $notificacion = null;
        $r = DB::transaction(function () use ($request, $record, $data, &$notificacion): RequerimientoDocumento {
            $r = $this->consulta($request)->lockForUpdate()->findOrFail($record);
            abort_unless(in_array($r->estado, self::ABIERTOS, true), 409, 'La solicitud está cerrada.');
            $this->legajoActivo($r->usuario_id);
            $cambios = [];
            if (in_array($data['accion'], ['observar', 'aceptar'], true)) {
                abort_unless($r->estado === 'en_revision', 409, 'Primero debe recibirse la respuesta del titular.');
                abort_if($r->usuario_id === $request->user()->id, 403, 'Otra persona debe revisar tu solicitud.');
                $cambios['estado'] = $data['accion'] === 'aceptar' ? 'aceptado' : 'observado';
            }
            if ($data['accion'] === 'aceptar') {
                if ($r->tipo_documento_id) {
                    $doc = DocumentoUsuario::query()->where('usuario_id', $r->usuario_id)->where('tipo_documento_id', $r->tipo_documento_id)
                        ->where('estado', 'aceptado')->whereHas('legajo', fn (Builder $q) => $q->where('estado_legajo', 'activo'))
                        ->whereDoesntHave('documentosUsuarioPorSubsanaADocumento')->find($data['documento_id'] ?? '');
                    if (! $doc) {
                        throw ValidationException::withMessages(['documento_id' => 'Incorpora y acepta el documento en su sección del legajo; luego selecciónalo para cerrar la solicitud.']);
                    }
                    $entrega = collect($r->seguimiento ?? [])->where('accion', 'respuesta')->last();
                    $hashes = isset($entrega['hash']) ? [$entrega['hash']] : array_column($entrega['archivos'] ?? [], 'hash');
                    if ($hashes && ! in_array($doc->archivo?->hash_sha256, $hashes, true)) {
                        throw ValidationException::withMessages(['documento_id' => 'El documento seleccionado no corresponde al PDF de la última entrega.']);
                    }
                    $cambios['documento_id'] = $doc->id;
                }
                $cambios['cerrado_en'] = now();
            }
            if ($data['accion'] === 'cancelar') {
                $cambios = ['estado' => 'cancelado', 'cerrado_en' => now()];
            }
            if ($data['accion'] === 'prorrogar') {
                abort_unless($r->fecha_limite->lte($data['prorroga_hasta']) && (! $r->prorroga_hasta || $r->prorroga_hasta->lte($data['prorroga_hasta'])), 422, 'La prórroga no puede reducir el plazo.');
                $cambios['prorroga_hasta'] = $data['prorroga_hasta'];
            }
            if ($data['accion'] === 'recordar') {
                abort_if($r->recordado_en?->timezone('America/Lima')->isToday(), 409, 'Ya se envió un recordatorio hoy.');
                $cambios['recordado_en'] = now();
            }
            $this->evento($r, $request, $data['accion'], $data['mensaje']);
            $r->update($cambios);
            if ($data['accion'] === 'recordar') {
                $notificacion = $this->notificar($r, MensajeRequerimiento::TITULO_RECORDATORIO, $data['mensaje']);
            }

            return $r;
        });

        if ($notificacion) {
            CorreoRequerimiento::procesar([$notificacion->id]);
        }

        return response()->json(['data' => ['id' => $r->id, 'estado' => $r->estado],
            'correo' => $notificacion?->fresh()->only(['correo_estado', 'correo_error'])]);
    }

    public function documentos(Request $request, string $record): JsonResponse
    {
        abort_unless($request->user()->can('requerimientos.gestionar'), 403);
        $r = $this->consulta($request)->findOrFail($record);

        return response()->json(['data' => DocumentoUsuario::query()->where('usuario_id', $r->usuario_id)->where('tipo_documento_id', $r->tipo_documento_id)
            ->where('estado', 'aceptado')->whereHas('legajo', fn (Builder $q) => $q->where('estado_legajo', 'activo'))
            ->whereDoesntHave('documentosUsuarioPorSubsanaADocumento')->get(['id', 'nombre', 'fecha_incorporacion', 'num_resolucion'])]);
    }

    public function archivo(Request $request, string $record, int $indice, int $adjunto = 0): StreamedResponse
    {
        $r = $this->consulta($request)->findOrFail($record);
        $evento = ($r->seguimiento ?? [])[$indice] ?? [];
        $evento = isset($evento['archivos']) ? ($evento['archivos'][$adjunto] ?? []) : ($adjunto === 0 ? $evento : []);
        abort_unless(isset($evento['ruta'], $evento['disco']), 404);
        abort_unless(Storage::disk($evento['disco'])->exists($evento['ruta']), 404);

        $nombre = $evento['archivo'] ?? 'entrega.pdf';

        return $request->boolean('ver')
            ? Storage::disk($evento['disco'])->response($evento['ruta'], $nombre, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'], 'inline')
            : Storage::disk($evento['disco'])->download($evento['ruta'], $nombre, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function sugerencias(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('requerimientos.gestionar') && ConsultaReportes::puede($request->user(), 'pendientes'), 403);
        $data = $request->validate(['q' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1']);

        return response()->json(ConsultaReportes::listar($request->user(), 'pendientes', [...$data, 'estado' => 'por_determinar', 'por_pagina' => 20]));
    }

    private function legajoActivo(string $usuario): void
    {
        abort_unless(Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->lockForUpdate()->first(), 409, 'La persona no tiene un legajo activo.');
    }

    private function tipoVisible(Request $request, string $id): void
    {
        $t = $this->tiposDisponibles()->find($id);
        if (! $t) {
            throw ValidationException::withMessages(['tipo_documento_ids' => 'Selecciona un documento disponible en las 11 secciones del legajo. Para otro asunto, no selecciones un tipo de documento.']);
        }
        abort_if(! $request->user()->veDatosSensibles() && ($t->es_dato_sensible || $t->es_dato_salud || in_array($t->seccion_legajo_id, [1, 9, 10], true)), 403);
    }

    private function evento(RequerimientoDocumento $r, Request $request, string $accion, string $mensaje, array $extra = []): void
    {
        $r->update(['seguimiento' => [...($r->seguimiento ?? []), ['accion' => $accion, 'mensaje' => $mensaje, 'autor' => $request->user()->id, 'fecha' => now()->toIso8601String(), ...$extra]]]);
    }

    private function notificar(RequerimientoDocumento $r, string $titulo, string $mensaje): NotificacionUsuario
    {
        return CorreoRequerimiento::programar($r, $titulo, $mensaje);
    }
}
