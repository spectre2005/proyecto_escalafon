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
use App\Support\Documentos\ArchivoPdf;
use App\Support\Parametros;
use App\Support\RequerimientosAutomaticos;
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
            ->when(! $request->user()->can('sistema.administrar'), fn (Builder $q) => $q->where('usuario_id', $request->user()->id))
            ->when($request->user()->can('sistema.administrar') && ! $request->user()->veDatosSensibles(), fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('tipo_documento_id')->orWhereHas('tipoDocumento', fn (Builder $t) => $t->where('es_dato_sensible', false)->where('es_dato_salud', false)->whereNotIn('seccion_legajo_id', [1, 9, 10]))));
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['clase' => 'nullable|in:documentos,actualizacion', 'estado' => 'nullable|in:abierto,observado,en_revision,aceptado,cancelado', 'q' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1']);
        $q = $this->consulta($request)->with(['usuario:id,nombres,apellido_paterno,apellido_materno', 'tipoDocumento:id,nombre', 'documento:id,nombre,estado']);
        foreach (['clase', 'estado'] as $campo) {
            if (! empty($data[$campo])) {
                $q->where($campo, $data[$campo]);
            }
        }
        if (! empty($data['q'])) {
            $term = '%'.mb_strtolower(trim($data['q'])).'%';
            $q->where(fn (Builder $q) => $q->whereRaw('LOWER(descripcion) LIKE ?', [$term])->orWhereHas('usuario', fn (Builder $u) => $u->whereRaw('LOWER(nombres) LIKE ?', [$term])->orWhereRaw('LOWER(apellido_paterno) LIKE ?', [$term])->orWhereRaw('LOWER(apellido_materno) LIKE ?', [$term])));
        }
        $pagina = $q->orderByDesc('created_at')->orderBy('id')->paginate(20)->withQueryString();
        $pagina->getCollection()->transform(function (RequerimientoDocumento $r): array {
            $datos = $r->toArray();
            $datos['seguimiento'] = array_map(function (array $evento): array {
                unset($evento['ruta'], $evento['disco']);

                return $evento;
            }, $r->seguimiento ?? []);
            $datos['atrasado'] = in_array($r->estado, self::ABIERTOS, true) && ($r->prorroga_hasta ?? $r->fecha_limite)->toDateString() < now('America/Lima')->toDateString();

            return $datos;
        });

        return response()->json([...$pagina->toArray(), 'avisos' => NotificacionUsuario::query()
            ->where('usuario_id', $request->user()->id)->whereIn('requerimiento_id', $this->consulta($request)->select('id'))
            ->where('tipo', 'solicitud_legajo')->orderByDesc('created_at')->limit(5)->get(['id', 'titulo', 'mensaje', 'created_at', 'requerimiento_id'])]);
    }

    public function sincronizar(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('sistema.administrar'), 403);

        return response()->json(['creados' => RequerimientosAutomaticos::sincronizar($request->user()),
            'plazo_dias' => max(1, Parametros::entero('requerimiento_plazo_dias_calendario'))]);
    }

    public function opciones(Request $request): JsonResponse
    {
        $admin = $request->user()->can('sistema.administrar');
        $personas = Usuario::query()->whereHas('legajos', fn (Builder $q) => $q->where('estado_legajo', 'activo'))
            ->when(! $admin, fn (Builder $q) => $q->whereKey($request->user()->id))->orderBy('apellido_paterno')->get(['id', 'nombres', 'apellido_paterno', 'apellido_materno']);
        $tipos = TipoDocumento::query()->where('activo', true)
            ->when($request->user()->can('sistema.administrar') && ! $request->user()->veDatosSensibles(), fn (Builder $q) => $q->where('es_dato_sensible', false)->where('es_dato_salud', false)->whereNotIn('seccion_legajo_id', [1, 9, 10]))
            ->orderBy('nombre')->get(['id', 'nombre']);

        return response()->json(['personas' => $personas, 'tipos' => $tipos, 'maximo_pdf_kb' => Parametros::entero('tamano_maximo_pdf_kb')]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $request->user()->can('sistema.administrar');
        $data = $request->validate([
            'clase' => 'required|in:documentos,actualizacion', 'usuario_id' => 'required|uuid|exists:usuarios,id',
            'descripcion' => 'required|string|max:300', 'tipo_documento_id' => 'nullable|uuid|exists:tipos_documento,id',
            'fecha_limite' => 'required|date_format:Y-m-d|after_or_equal:'.now('America/Lima')->toDateString(),
        ]);
        abort_unless($admin || ($data['clase'] === 'actualizacion' && $data['usuario_id'] === $request->user()->id), 403);
        $this->legajoActivo($data['usuario_id']);
        if (! empty($data['tipo_documento_id'])) {
            $this->tipoVisible($request, $data['tipo_documento_id']);
        }
        $r = DB::transaction(function () use ($data, $request): RequerimientoDocumento {
            // Serializa altas para evitar solicitudes abiertas duplicadas desde dos pestañas.
            Usuario::query()->whereKey($data['usuario_id'])->lockForUpdate()->firstOrFail();
            $this->legajoActivo($data['usuario_id']);
            $duplicado = RequerimientoDocumento::query()->where('usuario_id', $data['usuario_id'])->where('clase', $data['clase'])->whereIn('estado', self::ABIERTOS);
            if (! empty($data['tipo_documento_id'])) {
                $duplicado->where('tipo_documento_id', $data['tipo_documento_id']);
            } else {
                $duplicado->whereNull('tipo_documento_id')->where('descripcion', $data['descripcion']);
            }
            abort_if($duplicado->exists(), 409, 'Ya existe una solicitud abierta para este pendiente.');
            $r = RequerimientoDocumento::query()->create([...$data, 'origen' => $data['clase'] === 'actualizacion' ? 'docente' : 'legajo',
                'fecha_requerimiento' => now('America/Lima')->toDateString(), 'plazo_dias_habiles' => 0, 'estado' => 'abierto', 'solicitado_por' => $request->user()->id]);
            $this->evento($r, $request, 'creada', $data['descripcion']);
            $this->notificar($r, 'Solicitud registrada', $data['descripcion']);

            return $r;
        });

        return response()->json(['data' => $r], 201);
    }

    public function responder(Request $request, string $record): JsonResponse
    {
        $data = $request->validate(['mensaje' => 'required|string|max:3000', 'pdf' => 'nullable|file|max:'.Parametros::entero('tamano_maximo_pdf_kb')]);
        $pdf = $request->file('pdf');
        if ($pdf && ! ArchivoPdf::esPdf($pdf)) {
            throw ValidationException::withMessages(['pdf' => 'El archivo debe ser un PDF válido.']);
        }
        $ruta = null;
        $disco = (string) config('legajo.disco_documentos');
        try {
            $r = DB::transaction(function () use ($request, $record, $data, $pdf, $disco, &$ruta): RequerimientoDocumento {
                $r = $this->consulta($request)->lockForUpdate()->findOrFail($record);
                abort_unless($r->usuario_id === $request->user()->id, 403, 'Solo el titular puede responder.');
                $this->legajoActivo($r->usuario_id);
                abort_unless(in_array($r->estado, ['abierto', 'observado'], true), 409, 'La solicitud ya fue enviada a revisión o está cerrada.');
                $extra = ['canal' => 'plataforma'];
                if ($pdf) {
                    $ruta = $pdf->store('solicitudes/'.$r->id, $disco);
                    abort_unless($ruta, 500, 'No se pudo guardar el archivo.');
                    $extra = ['canal' => 'plataforma', 'ruta' => $ruta, 'disco' => $disco, 'archivo' => mb_substr($pdf->getClientOriginalName(), 0, 255), 'hash' => ArchivoPdf::hash($pdf)];
                }
                $this->evento($r, $request, 'respuesta', $data['mensaje'], $extra);
                $r->update(['estado' => 'en_revision']);
                $this->notificar($r, 'Entrega recibida', 'Tu respuesta está pendiente de revisión.');

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
        abort_unless($request->user()->can('sistema.administrar'), 403);
        $data = $request->validate(['accion' => 'required|in:observar,aceptar,cancelar,prorrogar,recordar', 'mensaje' => 'required|string|max:3000',
            'prorroga_hasta' => 'required_if:accion,prorrogar|nullable|date_format:Y-m-d|after_or_equal:'.now('America/Lima')->toDateString(), 'documento_id' => 'nullable|uuid|exists:documentos_usuario,id']);
        $r = DB::transaction(function () use ($request, $record, $data): RequerimientoDocumento {
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
                    if (isset($entrega['hash']) && $doc->archivo?->hash_sha256 !== $entrega['hash']) {
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
            $this->notificar($r, 'Seguimiento de tu solicitud', $data['mensaje']);

            return $r;
        });

        return response()->json(['data' => ['id' => $r->id, 'estado' => $r->estado]]);
    }

    public function documentos(Request $request, string $record): JsonResponse
    {
        abort_unless($request->user()->can('sistema.administrar'), 403);
        $r = $this->consulta($request)->findOrFail($record);

        return response()->json(['data' => DocumentoUsuario::query()->where('usuario_id', $r->usuario_id)->where('tipo_documento_id', $r->tipo_documento_id)
            ->where('estado', 'aceptado')->whereHas('legajo', fn (Builder $q) => $q->where('estado_legajo', 'activo'))
            ->whereDoesntHave('documentosUsuarioPorSubsanaADocumento')->get(['id', 'nombre', 'fecha_incorporacion', 'num_resolucion'])]);
    }

    public function archivo(Request $request, string $record, int $indice): StreamedResponse
    {
        $r = $this->consulta($request)->findOrFail($record);
        $evento = ($r->seguimiento ?? [])[$indice] ?? [];
        abort_unless(isset($evento['ruta'], $evento['disco']), 404);
        abort_unless(Storage::disk($evento['disco'])->exists($evento['ruta']), 404);

        return Storage::disk($evento['disco'])->download($evento['ruta'], 'entrega.pdf', ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function sugerencias(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('sistema.administrar') && ConsultaReportes::puede($request->user(), 'pendientes'), 403);
        $data = $request->validate(['q' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1']);

        return response()->json(ConsultaReportes::listar($request->user(), 'pendientes', [...$data, 'estado' => 'por_determinar', 'por_pagina' => 20]));
    }

    private function legajoActivo(string $usuario): void
    {
        abort_unless(Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->lockForUpdate()->first(), 409, 'La persona no tiene un legajo activo.');
    }

    private function tipoVisible(Request $request, string $id): void
    {
        $t = TipoDocumento::query()->where('activo', true)->findOrFail($id);
        abort_if($request->user()->can('sistema.administrar') && ! $request->user()->veDatosSensibles() && ($t->es_dato_sensible || $t->es_dato_salud || in_array($t->seccion_legajo_id, [1, 9, 10], true)), 403);
    }

    private function evento(RequerimientoDocumento $r, Request $request, string $accion, string $mensaje, array $extra = []): void
    {
        $r->update(['seguimiento' => [...($r->seguimiento ?? []), ['accion' => $accion, 'mensaje' => $mensaje, 'autor' => $request->user()->id, 'fecha' => now()->toIso8601String(), ...$extra]]]);
    }

    private function notificar(RequerimientoDocumento $r, string $titulo, string $mensaje): void
    {
        NotificacionUsuario::query()->create(['usuario_id' => $r->usuario_id, 'tipo' => 'solicitud_legajo', 'titulo' => $titulo, 'mensaje' => $mensaje, 'requerimiento_id' => $r->id]);
    }
}
