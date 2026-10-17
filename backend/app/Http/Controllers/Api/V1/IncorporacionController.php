<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Incorporacion;
use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\RegimenNormativo;
use App\Models\Rol;
use App\Models\SeccionLegajo;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use App\Models\VinculoLaboral;
use App\Support\ConfiguracionFuncionalidades;
use App\Support\Documentos\Antivirus;
use App\Support\Documentos\ArchivoPdf;
use App\Support\NumeroLegajo;
use App\Support\RegimenesLaborales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncorporacionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => 'nullable|string|max:120', 'tipo' => 'nullable|in:docente,administrativo', 'cargo' => 'nullable|string|max:200', 'archivo' => 'nullable|boolean']);
        $q = Incorporacion::query()->with(['usuario', 'responsable']);
        if (! $request->user()->can('sistema.administrar')) {
            $q->where('responsable_id', $request->user()->id);
        }
        $q->whereIn('estado', ($f['archivo'] ?? false) ? ['incorporado', 'rechazado'] : ['registrado', 'en_revision', 'observado', 'admitido']);
        $q->when($f['tipo'] ?? null, fn ($q, $tipo) => $q->whereHas('usuario', fn ($u) => $u->where('tipo_personal', $tipo)));
        $q->when($f['cargo'] ?? null, fn ($q, $cargo) => $q->whereRaw('LOWER(cargo) LIKE ?', ['%'.mb_strtolower($cargo).'%']));
        $q->when($f['q'] ?? null, fn ($q, $texto) => $q->whereHas('usuario', fn ($u) => $u->whereRaw("LOWER(nombres || ' ' || apellido_paterno || ' ' || COALESCE(apellido_materno, '') || ' ' || numero_doc_identidad) LIKE ?", ['%'.mb_strtolower($texto).'%'])));
        $page = $q->orderByDesc('updated_at')->orderBy('id')->paginate(40);
        $page->through(fn (Incorporacion $i) => $this->presentar($i));

        return response()->json($page);
    }

    public function opciones(): JsonResponse
    {
        return response()->json(['data' => [
            'responsables' => Usuario::query()->where('activo', true)->get()->filter(fn (Usuario $u) => $u->can('legajo.editar'))->map(fn ($u) => $u->only(['id', 'nombres', 'apellido_paterno']))->values(),
            'regimenes' => RegimenNormativo::query()->where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre', 'aplica_a', 'activo']),
            'roles' => Rol::query()->where('activo', true)->whereIn('codigo', array_keys(config('permisos.roles')))->get(['id', 'nombre', 'codigo']),
        ]]);
    }

    public function habilitarResponsable(Request $request): JsonResponse
    {
        $data = $request->validate(['usuario_id' => 'required|uuid|exists:usuarios,id', 'password' => ['nullable', 'string', 'max:255', Password::defaults()]]);
        DB::transaction(function () use ($request, $data): void {
            $usuario = Usuario::query()->lockForUpdate()->findOrFail($data['usuario_id']);
            abort_unless(Legajo::query()->where('usuario_id', $usuario->id)->where('estado_legajo', 'activo')->exists(), 422, 'El asistente debe ser personal con un legajo activo existente.');
            abort_if($usuario->can('legajo.editar'), 409, 'Esta persona ya tiene acceso para gestionar legajos.');
            $requiereClave = ! $usuario->activo || $usuario->permisos() === [];
            abort_if($requiereClave && empty($data['password']), 422, 'Define una contraseña inicial para habilitar la cuenta.');
            $rol = Rol::query()->where('codigo', 'asistente_legajo')->where('activo', true)->first();
            abort_unless($rol, 409, 'El rol de asistente está desactivado o no existe.');
            UsuarioRol::query()->create(['usuario_id' => $usuario->id, 'rol_id' => $rol->id, 'ambito_tipo_personal' => 'ambos', 'vigente_desde' => today()->toDateString(), 'asignado_por' => $request->user()->id]);
            if ($requiereClave) {
                $usuario->password = $data['password'];
            }
            $usuario->activo = true;
            $usuario->save();
        });

        return response()->json(['message' => 'Asistente habilitado. Ya puedes asignarle tareas de incorporación.']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'usuario_id' => 'nullable|uuid|exists:usuarios,id|unique:incorporaciones,usuario_id',
            'nombres' => 'required_without:usuario_id|string|max:120', 'apellido_paterno' => 'required_without:usuario_id|string|max:80',
            'apellido_materno' => 'nullable|string|max:80',
            'numero_doc_identidad' => 'required_without:usuario_id|regex:/^[0-9]{8}$/|unique:usuarios,numero_doc_identidad',
            'email_institucional' => 'required_without:usuario_id|email|max:255|unique:usuarios,email_institucional',
            'tipo_personal' => 'required_without:usuario_id|in:docente,administrativo', 'regimen_laboral' => 'required_without:usuario_id|string|max:20',
            'cargo' => 'required|string|max:150', 'numero_resolucion' => 'nullable|string|max:60',
            'fecha_resolucion' => 'nullable|date_format:Y-m-d', 'fecha_inicio' => 'nullable|date_format:Y-m-d',
            'documentos_exigidos' => 'required|array|min:1|max:30', 'documentos_exigidos.*' => 'required|string|max:150|distinct',
        ]);
        $i = DB::transaction(function () use ($data, $request): Incorporacion {
            if (! empty($data['usuario_id'])) {
                $u = Usuario::query()->lockForUpdate()->findOrFail($data['usuario_id']);
                abort_if(Incorporacion::query()->where('usuario_id', $u->id)->exists(), 409, 'Esta persona ya tiene una incorporación.');
            } else {
                abort_unless(in_array($data['regimen_laboral'], RegimenesLaborales::codigos($data['tipo_personal']), true), 422, 'El régimen no corresponde al tipo de personal.');
                $ley = RegimenesLaborales::ley(null, $data['regimen_laboral']);
                abort_if(RegimenesLaborales::error($ley, $data['tipo_personal']) !== null, 422, 'La ley debe estar activa y corresponder al tipo de personal.');
                $email = mb_strtolower($data['email_institucional']);
                abort_if(Usuario::query()->whereRaw('LOWER(email_institucional) = ?', [$email])->exists(), 422, 'El correo ya está registrado.');
                $u = Usuario::query()->create([...collect($data)->only(['nombres', 'apellido_paterno', 'apellido_materno', 'numero_doc_identidad', 'tipo_personal', 'regimen_laboral'])->all(),
                    'regimen_normativo_id' => $ley->id, 'email_institucional' => $email, 'password' => Str::random(64), 'activo' => false, 'tipo_doc_identidad' => 'dni']);
            }

            return Incorporacion::query()->create([
                ...collect($data)->only(['cargo', 'numero_resolucion', 'fecha_resolucion', 'fecha_inicio'])->all(),
                'usuario_id' => $u->id, 'registrado_por' => $request->user()->id, 'estado' => 'registrado',
                'documentos' => array_map(fn ($nombre) => ['id' => (string) Str::uuid(), 'nombre' => $nombre, 'estado' => 'pendiente', 'versiones' => []], $data['documentos_exigidos']),
                'tareas' => [], 'historial' => [['accion' => 'registrado', 'actor' => $request->user()->id, 'fecha' => now()->toIso8601String()]],
            ]);
        });

        return response()->json(['data' => $this->presentar($i)], 201);
    }

    public function show(Request $request, string $incorporacion): JsonResponse
    {
        $i = Incorporacion::query()->findOrFail($incorporacion);
        $this->autorizar($request, $i);

        return response()->json(['data' => $this->presentar($i)]);
    }

    public function actualizar(Request $request, string $incorporacion): JsonResponse
    {
        $data = $request->validate([
            'version' => 'required|integer|min:1', 'accion' => 'required|in:datos,revision,admitir,observar,rechazar,incorporar,delegar,tarea,firma,revisar_documento',
            'motivo' => 'nullable|string|max:1000', 'responsable_id' => 'nullable|uuid|exists:usuarios,id',
            'tareas' => 'sometimes|array|min:1|max:20', 'tareas.*' => 'required|string|max:300|distinct',
            'tarea_id' => 'nullable|uuid', 'completada' => 'sometimes|boolean',
            'documento_id' => 'nullable|uuid', 'estado_documento' => 'nullable|in:revisado,rechazado',
            'numero_resolucion' => 'nullable|string|max:60', 'fecha_resolucion' => 'nullable|date_format:Y-m-d', 'fecha_inicio' => 'nullable|date_format:Y-m-d',
            'rol_id' => 'nullable|uuid|exists:roles,id', 'password' => ['nullable', 'string', 'max:255', Password::defaults()],
        ]);

        return DB::transaction(function () use ($request, $incorporacion, $data): JsonResponse {
            $i = Incorporacion::query()->lockForUpdate()->findOrFail($incorporacion);
            $this->autorizar($request, $i);
            abort_unless($i->version === $data['version'], 409, 'El expediente cambió. Recarga antes de guardar.');
            abort_if(in_array($i->estado, ['incorporado', 'rechazado'], true), 409, 'La incorporación está cerrada.');
            $accion = $data['accion'];
            $admin = $request->user()->can('sistema.administrar');
            abort_unless($admin || in_array($accion, ['datos', 'revision', 'tarea'], true), 403, 'Esta acción requiere administración.');
            if (in_array($accion, ['datos', 'revision', 'tarea'], true) && ! $admin) {
                abort_unless(collect($i->tareas)->contains(fn ($t) => ! $t['completada']), 403, 'No tienes tareas pendientes en esta incorporación.');
            }
            if ($accion === 'datos') {
                abort_if($i->estado === 'admitido', 409, 'Observa el expediente antes de corregirlo.');
                $i->fill(collect($data)->only(['numero_resolucion', 'fecha_resolucion', 'fecha_inicio'])->all());
            } elseif ($accion === 'delegar') {
                $u = Usuario::query()->find($data['responsable_id'] ?? null);
                abort_unless($u?->activo && $u->can('legajo.editar') && ! empty($data['tareas']), 422, 'Selecciona un responsable activo y sus tareas.');
                $i->responsable_id = $u->id;
                $i->tareas = array_map(fn ($titulo) => ['id' => (string) Str::uuid(), 'titulo' => $titulo, 'completada' => false], $data['tareas']);
            } elseif ($accion === 'tarea') {
                abort_unless(collect($i->tareas)->contains('id', $data['tarea_id'] ?? null) && array_key_exists('completada', $data), 422, 'Selecciona una tarea válida.');
                $i->tareas = array_map(fn ($t) => $t['id'] === $data['tarea_id'] ? [...$t, 'completada' => $data['completada']] : $t, $i->tareas);
            } elseif ($accion === 'revisar_documento') {
                abort_unless($i->estado === 'en_revision', 409, 'Inicia la revisión del expediente.');
                $docs = $i->documentos;
                $pos = collect($docs)->search(fn ($d) => $d['id'] === ($data['documento_id'] ?? null));
                abort_if($pos === false || empty($data['estado_documento']), 422, 'Selecciona un documento y resultado.');
                $doc = $docs[$pos];
                abort_if(empty($doc['versiones']), 422, 'Primero carga el PDF del documento.');
                abort_if(end($doc['versiones'])['subido_por'] === $request->user()->id, 403, 'Quien sube el documento no puede revisarlo.');
                abort_if($data['estado_documento'] === 'rechazado' && empty($data['motivo']), 422, 'Indica el motivo del rechazo.');
                $docs[$pos] = [...$doc, 'estado' => $data['estado_documento'], 'revisado_por' => $request->user()->id, 'revisado_nombre' => $request->user()->nombres.' '.$request->user()->apellido_paterno, 'motivo' => $data['motivo'] ?? null];
                $i->documentos = $docs;
            } elseif ($accion === 'firma') {
                abort_unless(in_array($i->estado, ['en_revision', 'admitido'], true), 409, 'Primero inicia la revisión.');
                $i->firma_solicitada_at = now();
            } elseif ($accion === 'revision') {
                abort_unless(in_array($i->estado, ['registrado', 'observado'], true), 409, 'No se puede iniciar revisión desde este estado.');
                $i->estado = 'en_revision';
            } elseif (in_array($accion, ['observar', 'rechazar'], true)) {
                abort_if(empty($data['motivo']), 422, 'Indica el motivo.');
                $i->estado = $accion === 'observar' ? 'observado' : 'rechazado';
            } elseif ($accion === 'admitir') {
                abort_unless($i->estado === 'en_revision', 409, 'El expediente debe estar en revisión.');
                $this->exigirCompleto($i);
                $i->estado = 'admitido';
            } elseif ($accion === 'incorporar') {
                abort_unless($i->estado === 'admitido', 409, 'Primero marca al postulante como Admitido.');
                $this->exigirCompleto($i);
                $this->incorporar($request, $i, $data);
            }
            $i->historial = [...$i->historial, ['accion' => $accion, 'actor' => $request->user()->id, 'fecha' => now()->toIso8601String(), 'motivo' => $data['motivo'] ?? null, 'detalle' => collect($data)->except(['password', 'version', 'accion'])->all()]];
            $i->version++;
            $i->save();

            return response()->json(['data' => $this->presentar($i)]);
        });
    }

    public function subir(Request $request, string $incorporacion, string $documento): JsonResponse
    {
        $request->validate(['pdf' => 'required|file|mimes:pdf|max:20480', 'version' => 'required|integer']);
        abort_unless(ArchivoPdf::esPdf($request->file('pdf')), 422, 'Selecciona un PDF válido.');
        $path = null;
        try {
            return DB::transaction(function () use ($request, $incorporacion, $documento, &$path): JsonResponse {
                $i = Incorporacion::query()->lockForUpdate()->findOrFail($incorporacion);
                $this->autorizar($request, $i);
                abort_unless($i->version === (int) $request->input('version'), 409, 'El expediente cambió. Recarga antes de subir.');
                abort_unless(in_array($i->estado, ['registrado', 'observado', 'en_revision'], true), 409, 'No se permiten cargas en este estado.');
                abort_unless($request->user()->can('sistema.administrar') || collect($i->tareas)->contains(fn ($t) => ! $t['completada']), 403);
                $docs = $i->documentos;
                $pos = collect($docs)->search(fn ($d) => $d['id'] === $documento);
                abort_if($pos === false, 404);
                abort_if($docs[$pos]['estado'] === 'revisado', 409, 'El documento revisado se conserva.');
                $path = $request->file('pdf')->store('incorporaciones/'.$i->id, 'local');
                abort_unless($path, 500, 'No se pudo guardar el PDF.');
                app(Antivirus::class)->exigir('local', $path);
                $docs[$pos]['versiones'][] = ['path' => $path, 'nombre' => $request->file('pdf')->getClientOriginalName(), 'hash' => ArchivoPdf::hash($request->file('pdf')), 'subido_por' => $request->user()->id, 'fecha' => now()->toIso8601String()];
                $docs[$pos]['estado'] = 'cargado';
                unset($docs[$pos]['revisado_por'], $docs[$pos]['revisado_nombre'], $docs[$pos]['motivo']);
                $i->documentos = $docs;
                $i->historial = [...$i->historial, ['accion' => 'carga_documento', 'documento_id' => $documento, 'actor' => $request->user()->id, 'fecha' => now()->toIso8601String()]];
                $i->version++;
                $i->save();

                return response()->json(['data' => $this->presentar($i)]);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    public function archivo(Request $request, string $incorporacion, string $documento): StreamedResponse
    {
        $i = Incorporacion::query()->findOrFail($incorporacion);
        $this->autorizar($request, $i);
        $d = collect($i->documentos)->firstWhere('id', $documento);
        abort_if(empty($d['versiones']), 404);
        $v = end($d['versiones']);
        abort_unless(Storage::disk('local')->exists($v['path']), 404);
        app(Antivirus::class)->exigir('local', $v['path']);

        app(Antivirus::class)->exigir('local', $v['path']);

        return Storage::disk('local')->response($v['path'], $v['nombre'], ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function autorizar(Request $request, Incorporacion $i): void
    {
        abort_unless($request->user()->can('sistema.administrar') || ($request->user()->can('legajo.editar') && $i->responsable_id === $request->user()->id), 403);
    }

    private function exigirCompleto(Incorporacion $i): void
    {
        abort_if(empty($i->documentos) || collect($i->documentos)->contains(fn ($d) => $d['estado'] !== 'revisado'), 422, 'Todos los documentos exigidos deben estar revisados.');
        abort_unless($i->numero_resolucion && $i->fecha_resolucion && $i->fecha_inicio, 422, 'Completa la resolución, su fecha y el inicio del vínculo.');
        abort_if(collect($i->tareas)->contains(fn ($t) => ! $t['completada']), 422, 'Completa las tareas delegadas antes de admitir.');
    }

    private function incorporar(Request $request, Incorporacion $i, array $data): void
    {
        abort_unless(ConfiguracionFuncionalidades::activa('s02.vinculos'), 409, 'La incorporación requiere habilitar vínculos laborales.');
        $u = Usuario::query()->lockForUpdate()->findOrFail($i->usuario_id);
        $rol = Rol::query()->where('activo', true)->find($data['rol_id'] ?? null);
        abort_unless($rol && array_key_exists($rol->codigo, config('permisos.roles')), 422, 'Selecciona un rol habilitado.');
        abort_if(! $u->activo && empty($data['password']), 422, 'Define la contraseña de activación de la cuenta.');
        $vinculo = VinculoLaboral::query()->where('usuario_id', $u->id)->where('anulado', false)->whereNull('fecha_cese')->first();
        if ($vinculo) {
            abort_unless($vinculo->numero_documento_vinculo === $i->numero_resolucion && $vinculo->nombre_puesto === $i->cargo && $vinculo->fecha_inicio->format('Y-m-d') === $i->fecha_inicio, 409, 'Ya existe un vínculo abierto distinto. Revisa sus datos antes de incorporar.');
        } else {
            $regimen = RegimenesLaborales::ley($u->regimen_normativo_id, $u->regimen_laboral);
            abort_unless($regimen, 422, 'No existe un régimen normativo activo para esta persona.');
            $vinculo = VinculoLaboral::query()->create(['regimen_normativo_id' => $regimen->id, 'usuario_id' => $u->id, 'tipo_personal' => $u->tipo_personal, 'regimen_laboral' => null,
                'nombre_puesto' => $i->cargo, 'numero_documento_vinculo' => $i->numero_resolucion, 'fecha_inicio' => $i->fecha_inicio, 'registrado_por' => $request->user()->id]);
        }
        if (! Legajo::query()->where('usuario_id', $u->id)->where('estado_legajo', 'activo')->exists()) {
            abort_unless(SeccionLegajo::query()->whereBetween('id', [1, 13])->count() === 13, 409, 'El catálogo requiere las 13 secciones.');
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(?)', [crc32('numero_legajo')]);
            }
            $legajo = Legajo::query()->create(['usuario_id' => $u->id, 'numero_legajo' => NumeroLegajo::siguiente(), 'estado_legajo' => 'activo', 'fecha_apertura' => today()]);
            foreach (range(1, 13) as $seccion) {
                LegajoSeccion::query()->create(['legajo_id' => $legajo->id, 'seccion_legajo_id' => $seccion, 'ultimo_folio' => 0]);
            }
        }
        if (! UsuarioRol::query()->where('usuario_id', $u->id)->where('rol_id', $rol->id)->whereDate('vigente_desde', '<=', today())->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', today()))->exists()) {
            UsuarioRol::query()->create(['usuario_id' => $u->id, 'rol_id' => $rol->id, 'ambito_tipo_personal' => $u->tipo_personal, 'vigente_desde' => today()->toDateString(), 'asignado_por' => $request->user()->id]);
        }
        $u->fill(['activo' => true, 'nombre_puesto' => $i->cargo]);
        if (! empty($data['password']) && ! $u->getOriginal('activo')) {
            $u->password = $data['password'];
        }
        $u->save();
        $i->vinculo_laboral_id = $vinculo->id;
        $i->estado = 'incorporado';
        $i->incorporado_at = now();
    }

    private function presentar(Incorporacion $i): array
    {
        $i->loadMissing(['usuario', 'responsable']);
        $data = $i->toArray();
        $data['usuario'] = $i->usuario->only(['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'numero_doc_identidad', 'email_institucional', 'tipo_personal', 'regimen_laboral', 'activo']);
        $data['responsable'] = $i->responsable?->only(['id', 'nombres', 'apellido_paterno']);
        $data['documentos'] = array_map(function ($d) {
            $d['versiones'] = array_map(fn ($v) => collect($v)->except(['path', 'hash'])->all(), $d['versiones']);

            return $d;
        }, $i->documentos);
        $data['revisados'] = collect($i->documentos)->where('estado', 'revisado')->count();

        return $data;
    }
}
