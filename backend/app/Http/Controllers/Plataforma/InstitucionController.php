<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use App\Models\Plataforma\Respaldo;
use App\Rules\Ruc;
use App\Support\PasswordTemporal;
use App\Support\Plataforma\Aprovisionamiento;
use App\Support\Plataforma\AprovisionamientoRechazado;
use App\Support\Plataforma\AuditoriaOperaciones;
use App\Support\Plataforma\ResumenCobranza;
use App\Support\Plataforma\UsoInstitucion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Instituciones de la plataforma: resumen por estado, listado, registro, edición de sus datos básicos, suspensión y
 * reactivación (con motivo y auditoría) y aprovisionamiento automático, que crea su base y su carpeta (nunca borra ni
 * cambia las existentes). Suspender solo cambia su estado, y activar exige que ya esté aprovisionada. No hay rutas a
 * legajos, documentos ni personas.
 */
class InstitucionController extends Controller
{
    /** Minutos que se guarda la medición de uso y estado técnico de una institución. */
    private const USO_MINUTOS = 10;

    public function resumen(): JsonResponse
    {
        $conteo = Institucion::query()->select('estado', DB::raw('count(*) as total'))->groupBy('estado')->pluck('total', 'estado');
        $porEstado = collect(Institucion::ESTADOS)->mapWithKeys(fn (string $estado): array => [$estado => (int) ($conteo[$estado] ?? 0)]);

        $aprovisionadas = Institucion::query()->whereNotNull('aprovisionada_en')->orderBy('nombre')->get();
        $plataforma = Respaldo::query()->where('objetivo', 'plataforma')->latest('created_at')->first();

        return response()->json(['data' => [
            'total' => $porEstado->sum(), 'por_estado' => $porEstado,
            // Instituciones sin un respaldo correcto reciente, y el último respaldo de la base de la plataforma.
            'respaldos' => [
                'atrasados' => $aprovisionadas->filter(fn (Institucion $i): bool => $i->estadoRespaldo()['atrasado'])
                    ->map(fn (Institucion $i): array => ['id' => $i->id, 'nombre' => $i->nombre, 'ultimo_correcto_en' => $i->estadoRespaldo()['ultimo_correcto_en']])->values(),
                'plataforma' => $plataforma?->datos(),
                'horas_aviso' => Respaldo::HORAS_AVISO,
            ],
            // Tiempo de uso pagado: por vencer, en gracia y vencidas (Institucion::servicio()), e ingresos registrados.
            'vencimientos' => ResumenCobranza::vencimientos(),
            'ingresos' => ResumenCobranza::ingresos(),
            'recientes' => AuditoriaPlataforma::query()->with(['administrador:id,nombre', 'institucion:id,nombre'])->latest('created_at')->limit(8)->get()->map(fn (AuditoriaPlataforma $evento): array => $this->evento($evento)),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate(['q' => 'nullable|string|max:150', 'estado' => ['nullable', Rule::in(Institucion::ESTADOS)], 'page' => 'sometimes|integer|min:1']);
        $consulta = Institucion::query()->with('periodos')->orderBy('nombre')->orderBy('id');
        if (! empty($filtros['q'])) {
            $texto = '%'.mb_strtolower(trim($filtros['q'])).'%';
            $consulta->where(fn ($q) => $q->whereRaw('LOWER(nombre) LIKE ?', [$texto])->orWhereRaw('LOWER(slug) LIKE ?', [$texto]));
        }
        if (! empty($filtros['estado'])) {
            $consulta->where('estado', $filtros['estado']);
        }
        $pagina = $consulta->paginate(20)->withQueryString();

        return response()->json([...$pagina->toArray(), 'data' => collect($pagina->items())->map(fn (Institucion $i): array => $i->datos())]);
    }

    public function show(string $institucion): JsonResponse
    {
        $registro = Institucion::query()->findOrFail($institucion);

        return response()->json(['data' => [...$registro->datos(),
            'respaldos' => $registro->respaldos()->latest('created_at')->limit(10)->get()->map(fn (Respaldo $respaldo): array => $respaldo->datos()),
            'auditoria' => AuditoriaPlataforma::query()->where('institucion_id', $registro->id)->with('administrador:id,nombre')->latest('created_at')->limit(30)->get()
                ->map(fn (AuditoriaPlataforma $evento): array => $this->evento($evento)),
        ]]);
    }

    /**
     * Uso y estado técnico (UsoInstitucion). Recorrer la carpeta de archivos cuesta: el resultado se guarda
     * USO_MINUTOS en la caché y ?actualizar=1 lo vuelve a medir. Solo cifras y estados, sin datos personales.
     */
    public function uso(Request $request, string $institucion): JsonResponse
    {
        $request->validate(['actualizar' => 'sometimes|boolean']);
        $registro = Institucion::query()->findOrFail($institucion);
        $clave = 'plataforma:uso:'.$registro->id;
        if ($request->boolean('actualizar')) {
            Cache::forget($clave);
        }

        return response()->json(['data' => Cache::remember($clave, now()->addMinutes(self::USO_MINUTOS), fn (): array => UsoInstitucion::medir($registro))]);
    }

    /** Se registra sin aprovisionar: base y almacenamiento propuestos a partir del identificador (editables). */
    public function store(Request $request): JsonResponse
    {
        $this->sinCamposAjenos($request, ['nombre', 'slug', 'base_datos', 'almacenamiento']);
        $slug = mb_strtolower(trim((string) $request->input('slug')));
        $request->merge(['slug' => $slug, 'base_datos' => $request->input('base_datos') ?: 'escalafon_'.str_replace('-', '_', $slug),
            'almacenamiento' => $request->input('almacenamiento') ?: 'instituciones/'.$slug]);
        $data = $request->validate($this->reglas());
        $institucion = Institucion::query()->create([...$data, 'nombre' => trim($data['nombre']), 'estado' => Institucion::REGISTRADA]);
        AuditoriaOperaciones::registrar('institucion.registrada', $request, Auth::guard('plataforma')->id(), $institucion->id, null, $this->huella($institucion));

        return response()->json(['data' => $institucion->refresh()->datos()], 201);
    }

    /** Datos básicos. El identificador, la base y el almacenamiento solo se corrigen mientras no esté aprovisionada. */
    public function update(Request $request, string $institucion): JsonResponse
    {
        $registro = Institucion::query()->findOrFail($institucion);
        $this->sinCamposAjenos($request, ['nombre', 'slug', 'base_datos', 'almacenamiento', 'dias_gracia', ...Institucion::DATOS_CLIENTE]);
        $ubicacion = array_intersect(array_keys($request->all()), ['slug', 'base_datos', 'almacenamiento']);
        if ($ubicacion !== [] && ! $registro->ubicacionEditable()) {
            throw ValidationException::withMessages(array_fill_keys($ubicacion,
                'Ya está aprovisionada: su identificador, su base de datos y su almacenamiento no se cambian desde el panel.'));
        }
        if ($request->has('slug')) {
            $request->merge(['slug' => mb_strtolower(trim((string) $request->input('slug')))]);
        }
        // Datos del cliente: sin espacios sobrantes; vacío = sin dato. El correo, en minúsculas.
        foreach (array_intersect(Institucion::DATOS_CLIENTE, array_keys($request->all())) as $campo) {
            $valor = trim((string) $request->input($campo));
            $request->merge([$campo => $valor === '' ? null : ($campo === 'contacto_email' ? mb_strtolower($valor) : $valor)]);
        }
        $data = $request->validate([...array_map(fn (array $reglas): array => ['sometimes', ...$reglas], $this->reglas($registro->id)),
            // Días que sigue funcionando (con aviso) después de vencer su tiempo de uso.
            'dias_gracia' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'razon_social' => ['sometimes', 'nullable', 'string', 'min:3', 'max:200'],
            'ruc' => ['sometimes', 'nullable', 'string', new Ruc],
            'contacto_nombre' => ['sometimes', 'nullable', 'string', 'min:3', 'max:150'],
            'contacto_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'contacto_telefono' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9][0-9 ()-]{5,18}$/'],
            'contrato_numero' => ['sometimes', 'nullable', 'string', 'max:60'],
            'contrato_inicio' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31']],
            ['contacto_telefono.regex' => 'El teléfono solo puede tener dígitos, espacios, guiones, paréntesis y un + inicial.']);
        $antes = $this->huella($registro);
        $registro->update(isset($data['nombre']) ? [...$data, 'nombre' => trim($data['nombre'])] : $data);
        AuditoriaOperaciones::registrar('institucion.editada', $request, Auth::guard('plataforma')->id(), $registro->id, $antes, $this->huella($registro));

        return response()->json(['data' => $registro->refresh()->datos()]);
    }

    /** Suspender: sin acceso, conservando su base, sus archivos y sus datos. Requiere motivo. */
    public function suspender(Request $request, string $institucion): JsonResponse
    {
        $this->sinCamposAjenos($request, ['motivo']);
        $motivo = trim((string) $request->validate(['motivo' => 'required|string|min:5|max:500'], ['motivo.required' => 'Indica el motivo de la suspensión.'])['motivo']);

        return $this->transicion($request, $institucion, fn (Institucion $i): ?string => $i->motivoNoSuspendible(),
            ['estado' => Institucion::SUSPENDIDA, 'suspendida_en' => now(), 'motivo_suspension' => $motivo], 'institucion.suspendida', $motivo);
    }

    /**
     * Aprovisionamiento automático (Aprovisionamiento): base, esquema, catálogos, primer administrador y carpeta. La
     * contraseña temporal del administrador solo viaja en esta respuesta (no se guarda ni se audita).
     */
    public function aprovisionar(Request $request, string $institucion): JsonResponse
    {
        $this->sinCamposAjenos($request, ['dni', 'nombres', 'apellido_paterno', 'apellido_materno', 'email', 'vigencia_horas', 'vence_en']);
        $administrador = $request->validate([
            ...PasswordTemporal::reglasPlazo(),
            'dni' => ['nullable', 'string', 'regex:/\A[0-9]{8}\z/'],
            'apellido_materno' => 'nullable|string|max:80',
            'nombres' => 'required|string|min:2|max:120', 'apellido_paterno' => 'required|string|min:2|max:80', 'email' => 'required|email|max:255',
        ]);
        $administrador['password_temporal_vence_en'] = PasswordTemporal::limite($administrador);
        $registro = Institucion::query()->findOrFail($institucion);
        // Migrar y cargar catálogos toma unos segundos.
        set_time_limit(300);
        try {
            $clave = Aprovisionamiento::ejecutar($registro->id, $administrador, $request, Auth::guard('plataforma')->id());
        } catch (AprovisionamientoRechazado $rechazo) {
            abort(409, $rechazo->getMessage());
        } catch (Throwable $error) {
            report($error);
            abort(500, 'No se pudo aprovisionar. Se retiró lo que se había creado; el detalle quedó en la auditoría.');
        }

        return response()->json(['data' => [...$registro->refresh()->datos(),
            'administrador_inicial' => ['email' => mb_strtolower(trim($administrador['email'])), 'clave_temporal' => $clave, 'password_temporal_vence_en' => $administrador['password_temporal_vence_en']->toIso8601String()]]])
            ->header('Cache-Control', 'no-store');
    }

    /** Activar por primera vez o reactivar: solo si está aprovisionada. */
    public function activar(Request $request, string $institucion): JsonResponse
    {
        $this->sinCamposAjenos($request, ['motivo']);
        $motivo = $request->validate(['motivo' => 'nullable|string|max:500'])['motivo'] ?? null;

        return $this->transicion($request, $institucion, fn (Institucion $i): ?string => $i->motivoNoActivable(),
            ['estado' => Institucion::ACTIVA, 'suspendida_en' => null, 'motivo_suspension' => null], 'institucion.activada', $motivo ? trim($motivo) : null);
    }

    private function transicion(Request $request, string $institucion, \Closure $rechazo, array $cambios, string $accion, ?string $motivo): JsonResponse
    {
        $registro = DB::connection('plataforma')->transaction(function () use ($request, $institucion, $rechazo, $cambios, $accion, $motivo): Institucion {
            $registro = Institucion::query()->lockForUpdate()->findOrFail($institucion);
            if ($error = $rechazo($registro)) {
                abort(409, $error);
            }
            $antes = $this->huella($registro);
            $registro->update($cambios);
            AuditoriaOperaciones::registrar($accion, $request, Auth::guard('plataforma')->id(), $registro->id, $antes, $this->huella($registro), $motivo);

            return $registro;
        });

        return response()->json(['data' => $registro->refresh()->datos()]);
    }

    /** @return array<string, list<mixed>> */
    private function reglas(?string $ignorar = null): array
    {
        $unico = fn (string $columna) => Rule::unique('plataforma.instituciones', $columna)->ignore($ignorar);

        return [
            'nombre' => ['required', 'string', 'min:3', 'max:150'],
            'slug' => ['required', 'string', 'regex:'.Institucion::PATRON_SLUG, Rule::notIn(Institucion::SLUGS_RESERVADOS), $unico('slug')],
            'base_datos' => ['required', 'string', 'regex:'.Institucion::PATRON_BASE_DATOS, Rule::notIn([config('database.connections.plataforma.database'), 'postgres', 'template0', 'template1']), $unico('base_datos')],
            'almacenamiento' => ['required', 'string', 'regex:'.Institucion::PATRON_ALMACENAMIENTO, $unico('almacenamiento')],
        ];
    }

    private function sinCamposAjenos(Request $request, array $permitidos): void
    {
        $ajenos = array_diff(array_keys($request->all()), $permitidos);
        if ($ajenos !== []) {
            throw ValidationException::withMessages(array_fill_keys($ajenos, 'Campo no permitido.'));
        }
    }

    /** @return array<string, mixed> */
    private function huella(Institucion $institucion): array
    {
        return [...$institucion->only(['nombre', 'slug', 'estado', 'base_datos', 'almacenamiento', 'motivo_suspension', 'dias_gracia',
            'razon_social', 'ruc', 'contacto_nombre', 'contacto_email', 'contacto_telefono', 'contrato_numero']),
            'contrato_inicio' => $institucion->contrato_inicio?->toDateString()];
    }

    /** @return array<string, mixed> */
    private function evento(AuditoriaPlataforma $evento): array
    {
        return [...$evento->only(['id', 'accion', 'institucion_id', 'datos_antes', 'datos_despues', 'motivo']),
            'administrador' => $evento->administrador?->nombre ?? (($evento->datos_despues['origen'] ?? null) === 'institucion' ? ($evento->datos_despues['por'] ?? 'Administración institucional') : null), 'institucion' => $evento->institucion?->nombre,
            'created_at' => $evento->created_at?->toIso8601String()];
    }
}
