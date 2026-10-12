<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\AuditoriaOperaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Instituciones de la plataforma: resumen por estado, listado, registro, edición de sus datos básicos, suspensión y
 * reactivación (con motivo y auditoría). Nada de esto toca la base ni los archivos de la institución: suspender solo
 * cambia su estado, y activar exige que ya esté aprovisionada. No hay rutas a legajos, documentos ni personas.
 */
class InstitucionController extends Controller
{
    private const SLUG = '/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/';

    private const BASE_DATOS = '/^[a-z][a-z0-9_]{2,62}$/';

    private const ALMACENAMIENTO = '/^instituciones\/[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/';

    public function resumen(): JsonResponse
    {
        $conteo = Institucion::query()->select('estado', DB::raw('count(*) as total'))->groupBy('estado')->pluck('total', 'estado');
        $porEstado = collect(Institucion::ESTADOS)->mapWithKeys(fn (string $estado): array => [$estado => (int) ($conteo[$estado] ?? 0)]);

        return response()->json(['data' => [
            'total' => $porEstado->sum(), 'por_estado' => $porEstado,
            'recientes' => AuditoriaPlataforma::query()->with(['administrador:id,nombre', 'institucion:id,nombre'])->latest('created_at')->limit(8)->get()->map(fn (AuditoriaPlataforma $evento): array => $this->evento($evento)),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate(['q' => 'nullable|string|max:150', 'estado' => ['nullable', Rule::in(Institucion::ESTADOS)], 'page' => 'sometimes|integer|min:1']);
        $consulta = Institucion::query()->orderBy('nombre')->orderBy('id');
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
            'auditoria' => AuditoriaPlataforma::query()->where('institucion_id', $registro->id)->with('administrador:id,nombre')->latest('created_at')->limit(30)->get()
                ->map(fn (AuditoriaPlataforma $evento): array => $this->evento($evento)),
        ]]);
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
        $this->sinCamposAjenos($request, ['nombre', 'slug', 'base_datos', 'almacenamiento']);
        $ubicacion = array_intersect(array_keys($request->all()), ['slug', 'base_datos', 'almacenamiento']);
        if ($ubicacion !== [] && ! $registro->ubicacionEditable()) {
            throw ValidationException::withMessages(array_fill_keys($ubicacion,
                'Ya está aprovisionada: su identificador, su base de datos y su almacenamiento no se cambian desde el panel.'));
        }
        if ($request->has('slug')) {
            $request->merge(['slug' => mb_strtolower(trim((string) $request->input('slug')))]);
        }
        $data = $request->validate(array_map(fn (array $reglas): array => ['sometimes', ...$reglas], $this->reglas($registro->id)));
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
            'slug' => ['required', 'string', 'regex:'.self::SLUG, Rule::notIn(Institucion::SLUGS_RESERVADOS), $unico('slug')],
            'base_datos' => ['required', 'string', 'regex:'.self::BASE_DATOS, Rule::notIn([config('database.connections.plataforma.database'), 'postgres', 'template0', 'template1']), $unico('base_datos')],
            'almacenamiento' => ['required', 'string', 'regex:'.self::ALMACENAMIENTO, $unico('almacenamiento')],
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
        return $institucion->only(['nombre', 'slug', 'estado', 'base_datos', 'almacenamiento', 'motivo_suspension']);
    }

    /** @return array<string, mixed> */
    private function evento(AuditoriaPlataforma $evento): array
    {
        return [...$evento->only(['id', 'accion', 'institucion_id', 'datos_antes', 'datos_despues', 'motivo']),
            'administrador' => $evento->administrador?->nombre, 'institucion' => $evento->institucion?->nombre,
            'created_at' => $evento->created_at?->toIso8601String()];
    }
}
