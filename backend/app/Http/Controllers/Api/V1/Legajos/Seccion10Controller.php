<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaEvento;
use App\Models\BitacoraAccesoLegajo;
use App\Models\DocumentoUsuario;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\ArchivoPdf;
use App\Support\Documentos\Folios;
use App\Support\Documentos\RegistroSeccion10;
use App\Support\Documentos\RevisionDocumento;
use App\Support\Parametros;
use App\Support\ResumenSeccion10;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Seccion10Controller extends Controller
{
    public function show(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->with(['unidadOrganica', 'vinculosLaborales.unidadOrganica'])->findOrFail($usuario);
        $legajo = $persona->legajos()->where('estado_legajo', 'activo')->orderByDesc('fecha_apertura')->orderByDesc('id')->first();
        $hoy = now('America/Lima')->toDateString();
        $tipos = TipoDocumento::query()->where('seccion_legajo_id', 10)->whereIn('codigo', array_keys(ResumenSeccion10::TIPOS))->orderBy('orden')->get();
        $docs = DocumentoUsuario::query()->where('usuario_id', $usuario)->where('seccion_legajo_id', 10)
            ->with(['archivo', 'legajo', 'tipoDocumento.subseccionLegajo', 'registrosSstBienestar', 'documentosUsuarioPorSubsanaADocumento', 'createdBy'])
            ->orderByDesc('fecha_emision')->orderByDesc('id')->get()->map(fn (DocumentoUsuario $doc): array => $this->documento($doc, $request));
        $items = $tipos->map(fn (TipoDocumento $tipo): array => ResumenSeccion10::item($tipo->only(['id', 'codigo', 'nombre', 'activo', 'descripcion', 'requiere_fedateo']),
            $docs->where('tipo_documento_id', $tipo->id), $hoy));
        $folios = ResumenSeccion10::folios($items);
        $folios['total'] = $docs->filter(fn (array $doc): bool => ! $doc['anulado'] && ! $doc['subsanado'])->sum('numero_folios');
        $folios['sin_clasificar'] = $folios['total'] - $folios['sst'] - $folios['bienestar'];
        $vinculos = $persona->vinculosLaborales->where('anulado', false);
        $actual = $vinculos->filter(fn ($v): bool => $v->fecha_inicio->format('Y-m-d') <= $hoy
            && (! $v->fecha_cese || $v->fecha_cese->format('Y-m-d') >= $hoy))->sortByDesc('fecha_inicio')->first();

        return response()->json(['data' => [
            'persona' => [...$persona->only(['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'numero_doc_identidad', 'tipo_doc_identidad',
                'tipo_personal', 'regimen_laboral', 'nombre_puesto', 'telefono', 'email_institucional']),
                'nombre_completo' => trim($persona->nombres.' '.$persona->apellido_paterno.' '.$persona->apellido_materno),
                'fecha_nacimiento' => $persona->fecha_nacimiento?->format('Y-m-d'),
                'cargo' => $actual?->nombre_puesto ?? $persona->nombre_puesto,
                'unidad_organica' => $actual?->unidadOrganica?->nombre ?? $persona->unidadOrganica?->nombre,
                'fecha_ingreso' => $vinculos->sortBy('fecha_inicio')->first()?->fecha_inicio?->format('Y-m-d')],
            'legajo' => $legajo?->only(['id', 'numero_legajo']), 'items' => $items->all(), 'folios' => $folios,
            'fecha_actual' => $hoy, 'folio_sugerido' => $legajo ? Folios::ultimo($legajo, 10) + 1 : null,
            'tamano_maximo_kb' => Parametros::entero('tamano_maximo_pdf_kb'),
        ]]);
    }

    public function store(Request $request, string $usuario): JsonResponse
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $request->validate(['tipo_documento_id' => ['required', 'uuid', Rule::exists('tipos_documento', 'id')->where('seccion_legajo_id', 10)->where('activo', true)]]);
        $tipo = TipoDocumento::query()->findOrFail($request->input('tipo_documento_id'));
        abort_unless(isset(ResumenSeccion10::TIPOS[$tipo->codigo]), 422, 'Selecciona un tipo del catálogo SST y Bienestar Social.');
        $doc = RegistroSeccion10::crear($request, $persona, $tipo, $this->validar($request, $tipo));

        return response()->json(['data' => $doc->only(['id', 'nombre', 'estado', 'archivo_id', 'folio_inicio', 'folio_fin'])], 201);
    }

    public function update(Request $request, string $usuario, string $documento): JsonResponse
    {
        $doc = $this->documentoDe($usuario, $documento);
        $datos = $this->validar($request, $doc->tipoDocumento, true);
        $doc = RegistroSeccion10::actualizar($request, $usuario, $documento, $datos);

        return response()->json(['data' => $doc->only(['id', 'nombre', 'estado'])]);
    }

    public function cambiarEstado(Request $request, string $usuario, string $documento): JsonResponse
    {
        $request->validate(['estado' => 'required|in:aceptado,observado']);

        return RevisionDocumento::cambiarEstado($request, $usuario, $documento, 10, 'sst.documento');
    }

    public function anular(Request $request, string $usuario, string $documento): JsonResponse
    {
        $reglas = ['motivo' => 'required|string|max:3000', 'estado_anterior' => 'required|string|max:20'];
        $this->rechazarDesconocidos($request, $reglas);
        $datos = $request->validate($reglas);
        $datos['motivo'] = trim($datos['motivo']);
        if ($datos['motivo'] === '') {
            throw ValidationException::withMessages(['motivo' => 'Explica el motivo de la anulación.']);
        }
        $doc = RegistroSeccion10::anular($request, $usuario, $documento, $datos);

        return response()->json(['data' => $doc->only(['id', 'estado'])]);
    }

    public function historial(Request $request, string $usuario, string $documento): JsonResponse
    {
        $doc = $this->documentoDe($usuario, $documento)->load(['archivo', 'legajo', 'registrosSstBienestar', 'documentosUsuarioPorSubsanaADocumento', 'createdBy']);
        $eventos = AuditoriaEvento::query()->where('entidad', 'documentos_usuario')->where('entidad_id', $doc->id)
            ->with('actorUsuario')->orderByDesc('id')->get()->map(fn ($evento): array => [
                'id' => $evento->id, 'accion' => $evento->accion, 'fecha' => $evento->fecha_evento->toISOString(),
                'autor' => $this->nombre($evento->actorUsuario), 'usuario_id' => $evento->actor_usuario_id,
            ]);
        $estados = $doc->historialEstadosDocumento()->with('cambiadoPor')->orderByDesc('fecha_cambio')->get()->map(fn ($estado): array => [
            'id' => $estado->id, 'estado_anterior' => $estado->estado_anterior, 'estado_nuevo' => $estado->estado_nuevo,
            'fecha' => $estado->fecha_cambio->toISOString(), 'autor' => $this->nombre($estado->cambiadoPor), 'motivo' => $estado->motivo,
        ]);
        $accesos = BitacoraAccesoLegajo::query()->where('documento_id', $doc->id)->with('usuario')->orderByDesc('fecha_acceso')->get()
            ->map(fn ($acceso): array => ['id' => $acceso->id, 'accion' => $acceso->accion, 'fecha' => $acceso->fecha_acceso->toISOString(), 'autor' => $this->nombre($acceso->usuario)]);

        return response()->json(['data' => ['documento' => $this->documento($doc, $request), 'eventos' => $eventos, 'estados' => $estados, 'accesos' => $accesos]]);
    }

    private function validar(Request $request, TipoDocumento $tipo, bool $editar = false): array
    {
        abort_unless(isset(ResumenSeccion10::TIPOS[$tipo->codigo]), 422, 'Este documento no pertenece al catálogo SST.');
        [, , $periodo, $vigencia, $eps] = ResumenSeccion10::TIPOS[$tipo->codigo];
        $hoy = now('America/Lima')->toDateString();
        $reglas = ['nombre' => 'required|string|max:200', 'emisor' => 'required|string|max:200',
            'fecha_emision' => 'required|date_format:Y-m-d|before_or_equal:'.$hoy,
            'numero_documento' => 'nullable|string|max:60', 'descripcion' => 'nullable|string|max:300',
            'modalidad_presentacion' => ['nullable', Rule::in(DocumentoLegajoController::MODALIDADES)]];
        if ($periodo) {
            $reglas += ['fecha_inicio' => 'required|date_format:Y-m-d', 'fecha_fin' => ($eps ? 'nullable' : 'required').'|date_format:Y-m-d|after_or_equal:fecha_inicio'];
        }
        if ($vigencia) {
            $reglas['fecha_vencimiento'] = 'nullable|date_format:Y-m-d|after_or_equal:fecha_emision'.($eps ? '|after_or_equal:fecha_inicio' : '');
        }
        if ($eps) {
            $reglas['entidad'] = 'required|string|max:200';
        }
        if ($editar) {
            $reglas['estado_anterior'] = 'required|string|max:20';
        } else {
            $reglas += ['tipo_documento_id' => 'required|uuid', 'pendiente' => 'sometimes|boolean', 'subsana_a_documento_id' => 'nullable|uuid'];
            $pendiente = $request->boolean('pendiente');
            $reglas['pdf'] = $pendiente ? 'prohibited' : ['required', 'file', 'max:'.Parametros::entero('tamano_maximo_pdf_kb')];
            $reglas['numero_folios'] = $pendiente ? 'prohibited' : 'required|integer|between:1,2147483647';
            if (! $pendiente) {
                $reglas['modalidad_presentacion'][0] = 'required';
            }
        }
        $this->rechazarDesconocidos($request, $reglas);
        $datos = $request->validate($reglas);
        foreach (['nombre', 'emisor', 'numero_documento', 'descripcion', 'entidad'] as $campo) {
            if (isset($datos[$campo])) {
                $datos[$campo] = trim($datos[$campo]);
                if (in_array($campo, ['nombre', 'emisor', 'entidad'], true) && $datos[$campo] === '') {
                    throw ValidationException::withMessages([$campo => 'Completa este dato.']);
                }
            }
        }
        if (! $editar && ! ($datos['pendiente'] ?? false) && ! ArchivoPdf::esPdf($request->file('pdf'))) {
            throw ValidationException::withMessages(['pdf' => 'Selecciona un archivo que contenga un PDF válido.']);
        }

        return $datos;
    }

    private function rechazarDesconocidos(Request $request, array $reglas): void
    {
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }
    }

    private function documentoDe(string $usuario, string $documento): DocumentoUsuario
    {
        Usuario::query()->findOrFail($usuario);

        return DocumentoUsuario::query()->where('usuario_id', $usuario)->where('seccion_legajo_id', 10)->with('tipoDocumento')->findOrFail($documento);
    }

    private function documento(DocumentoUsuario $doc, Request $request): array
    {
        $registro = $doc->registrosSstBienestar->first(fn ($r): bool => $r->usuario_id === $doc->usuario_id);
        $hoy = now('America/Lima')->toDateString();
        $vence = $registro?->fecha_vencimiento?->format('Y-m-d');
        if ($doc->tipoDocumento->codigo === 'S10_EPS' && $registro?->fecha_fin) {
            $fin = $registro->fecha_fin->format('Y-m-d');
            $vence = $vence ? min($vence, $fin) : $fin;
        }
        $disco = config('legajo.disco_documentos');
        $disponible = $doc->archivo && $doc->archivo->almacenamiento === $disco && $doc->archivo->estado_antivirus !== 'infectado'
            && Storage::disk($disco)->exists($doc->archivo->storage_path);
        $subsanado = $doc->documentosUsuarioPorSubsanaADocumento->isNotEmpty();
        $anulado = $doc->estado === 'anulado' || ($registro?->anulado ?? false);
        $activo = $doc->legajo->estado_legajo === 'activo' && ! $anulado && ! $subsanado;
        $estadoEps = $anulado || $subsanado ? 'inactivo' : ($vence && $vence < $hoy ? 'vencido'
            : ($registro?->fecha_inicio?->format('Y-m-d') > $hoy || ! $disponible ? 'pendiente' : 'activo'));

        return [...$doc->only(['id', 'nombre', 'emisor', 'tipo_documento_id', 'folio_inicio', 'folio_fin', 'modalidad_presentacion', 'subsana_a_documento_id', 'fedateado', 'fiscalizado', 'created_by']),
            ...($registro?->only(['descripcion', 'numero_documento', 'entidad', 'estado_tramite', 'motivo_anulacion']) ?? []),
            'estado_original' => $doc->estado, 'anulado' => $anulado, 'subsanado' => $subsanado, 'archivo_disponible' => (bool) $disponible,
            'archivo' => $doc->archivo?->only(['id', 'nombre_original', 'mime_type', 'tamano_bytes', 'num_paginas', 'estado_antivirus']),
            'fecha_emision' => $doc->fecha_emision?->format('Y-m-d'), 'fecha_incorporacion' => $doc->fecha_incorporacion?->format('Y-m-d'),
            'fecha_recepcion' => $doc->fecha_recepcion?->format('Y-m-d'), 'fecha_inicio' => $registro?->fecha_inicio?->format('Y-m-d'),
            'fecha_fin' => $registro?->fecha_fin?->format('Y-m-d'), 'fecha_vencimiento' => $registro?->fecha_vencimiento?->format('Y-m-d'),
            'vence_en' => $vence, 'estado_eps' => $doc->tipoDocumento->codigo === 'S10_EPS' ? $estadoEps : null,
            'numero_dias' => $registro?->fecha_inicio && $registro?->fecha_fin ? (int) $registro->fecha_inicio->diffInDays($registro->fecha_fin) + 1 : null,
            'numero_folios' => $doc->folio_inicio && $doc->folio_fin ? $doc->folio_fin - $doc->folio_inicio + 1 : 0,
            'creado_por' => $this->nombre($doc->createdBy), 'creado_en' => $doc->created_at?->toISOString(), 'modificado_en' => $doc->updated_at?->toISOString(),
            'puede_editar' => $activo && $registro && RegistroSeccion10::editable($doc),
            'puede_revisar' => $activo && $disponible && $doc->created_by !== $request->user()->getAuthIdentifier(),
            'puede_anular' => $activo && $registro !== null, 'puede_subsanar' => $activo && $registro !== null,
        ];
    }

    private function nombre(?Usuario $usuario): string
    {
        return $usuario ? trim($usuario->nombres.' '.$usuario->apellido_paterno.' '.$usuario->apellido_materno) : 'Usuario no disponible';
    }
}
