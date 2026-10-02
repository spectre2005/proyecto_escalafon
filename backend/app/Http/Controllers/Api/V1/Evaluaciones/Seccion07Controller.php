<?php

namespace App\Http\Controllers\Api\V1\Evaluaciones;

use App\Http\Controllers\Controller;
use App\Models\Desplazamiento;
use App\Models\EvaluacionDesempeno;
use App\Models\ProgresionCarrera;
use App\Models\RatificacionDocente;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Consulta (solo lectura) de la sección 07 del legajo: evaluación de desempeño,
 * progresión en la carrera, ratificaciones docentes y desplazamientos.
 * El registro se hace desde el legajo; aquí no se crea ni se modifica nada.
 */
class Seccion07Controller extends Controller
{
    /** tipo => [modelo, columna que define el año] */
    private const TIPOS = [
        'evaluaciones' => [EvaluacionDesempeno::class, 'ciclo_anio'],
        'progresiones' => [ProgresionCarrera::class, 'fecha_efectiva'],
        'ratificaciones' => [RatificacionDocente::class, 'fecha_resolucion'],
        'desplazamientos' => [Desplazamiento::class, 'fecha_inicio'],
    ];

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'unidades' => UnidadOrganica::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'anios' => collect(self::TIPOS)->map(fn (array $tipo, string $clave): array => $this->anios($clave))->all(),
            've_calificacion' => $request->user()->can('seccion07.ver_calificacion'),
        ]]);
    }

    public function index(Request $request, string $tipo): JsonResponse
    {
        abort_unless(isset(self::TIPOS[$tipo]), 404);
        $filtros = $request->validate([
            'q' => 'nullable|string|max:120',
            'anio' => 'nullable|integer|between:1900,2200',
            'unidad_organica_id' => 'nullable|uuid',
            'plan_mejora' => 'nullable|boolean',
            'por_pagina' => 'nullable|integer|in:10,20,50',
            'page' => 'sometimes|integer|min:1',
        ]);
        [$modelo, $columnaAnio] = self::TIPOS[$tipo];
        $query = $modelo::query()->where('anulado', false)
            ->with(['usuario:id,nombres,apellido_paterno,apellido_materno,numero_doc_identidad,unidad_organica_id', 'usuario.unidadOrganica:id,nombre']);

        if (! empty($filtros['q'])) {
            // Espacios normalizados: "ana  quispe" busca igual que "ana quispe".
            $busqueda = '%'.mb_strtolower(preg_replace('/\s+/u', ' ', trim($filtros['q']))).'%';
            // ' ' || NULL es NULL: sin apellido materno no queda un doble espacio en el nombre.
            $query->whereHas('usuario', fn (Builder $usuario): Builder => $usuario->where(fn (Builder $q): Builder => $q
                ->whereRaw("LOWER(nombres || ' ' || apellido_paterno || COALESCE(' ' || apellido_materno, '')) LIKE ?", [$busqueda])
                ->orWhereRaw("LOWER(apellido_paterno || COALESCE(' ' || apellido_materno, '') || ' ' || nombres) LIKE ?", [$busqueda])
                ->orWhere('numero_doc_identidad', 'like', $busqueda)));
        }
        if (! empty($filtros['unidad_organica_id'])) {
            $query->whereHas('usuario', fn (Builder $usuario): Builder => $usuario->where('unidad_organica_id', $filtros['unidad_organica_id']));
        }
        if (! empty($filtros['anio'])) {
            $anio = (int) $filtros['anio'];
            $columnaAnio === 'ciclo_anio'
                ? $query->where('ciclo_anio', $anio)
                : $query->where($columnaAnio, '>=', $anio.'-01-01')->where($columnaAnio, '<', ($anio + 1).'-01-01');
        }
        if ($tipo === 'evaluaciones' && $request->boolean('plan_mejora')) {
            $query->where('plan_mejora', true);
        }
        match ($tipo) {
            'evaluaciones' => $query->with('evaluador:id,nombres,apellido_paterno,apellido_materno')->orderByDesc('ciclo_anio')->orderByDesc('fecha_evaluacion'),
            'desplazamientos' => $query->with(['unidadOrigen:id,nombre', 'unidadDestino:id,nombre'])->orderByDesc('fecha_inicio'),
            default => $query->orderByDesc($columnaAnio),
        };

        $veCalificacion = $request->user()->can('seccion07.ver_calificacion');
        $pagina = $query->orderBy('id')->paginate($filtros['por_pagina'] ?? 20)->withQueryString();
        $pagina->through(fn (Model $registro): array => $this->presentar($tipo, $registro, $veCalificacion));

        return response()->json($pagina);
    }

    /** @return array<string, mixed> */
    private function presentar(string $tipo, Model $r, bool $veCalificacion): array
    {
        $base = [
            'id' => $r->id,
            'persona' => $this->persona($r->usuario),
            'tiene_documento' => $r->documento_id !== null,
        ];

        return $base + match ($tipo) {
            'evaluaciones' => [
                'ciclo_anio' => $r->ciclo_anio,
                'fecha' => $this->fecha($r->fecha_evaluacion),
                // Pendiente de decisión del cliente: sin seccion07.ver_calificacion no se envían.
                'calificacion' => $veCalificacion ? $r->calificacion : null,
                'puntaje' => $veCalificacion ? $r->puntaje : null,
                'calificacion_oculta' => ! $veCalificacion,
                'plan_mejora' => $r->plan_mejora,
                'evaluador' => $r->evaluador ? $this->nombre($r->evaluador) : null,
            ],
            'progresiones' => [
                'fecha' => $this->fecha($r->fecha_efectiva),
                'nivel_anterior' => $r->nivel_anterior,
                'nivel_nuevo' => $r->nivel_nuevo,
                'numero_resolucion' => $r->numero_resolucion,
            ],
            'ratificaciones' => [
                'fecha' => $this->fecha($r->fecha_resolucion),
                'tipo' => $r->tipo,
                'categoria_anterior' => $r->categoria_anterior,
                'categoria_nueva' => $r->categoria_nueva,
                'numero_resolucion' => $r->numero_resolucion,
                'periodo_inicio' => $this->fecha($r->fecha_inicio_periodo),
                'periodo_vencimiento' => $this->fecha($r->fecha_vencimiento_periodo),
                'resultado' => $r->resultado,
            ],
            'desplazamientos' => [
                'fecha' => $this->fecha($r->fecha_inicio),
                'fecha_fin' => $this->fecha($r->fecha_fin),
                'tipo' => $r->tipo,
                'unidad_origen' => $r->unidadOrigen?->nombre,
                'unidad_destino' => $r->unidadDestino?->nombre,
                'puesto_destino' => $r->puesto_destino,
                'numero_documento' => $r->numero_documento,
            ],
        };
    }

    /** @return array<string, mixed>|null */
    private function persona(?Usuario $usuario): ?array
    {
        return $usuario ? [
            'id' => $usuario->id,
            'nombre' => $this->nombre($usuario),
            'documento' => $usuario->numero_doc_identidad,
            'unidad' => $usuario->unidadOrganica?->nombre,
        ] : null;
    }

    private function nombre(Usuario $usuario): string
    {
        return trim($usuario->apellido_paterno.' '.$usuario->apellido_materno).', '.$usuario->nombres;
    }

    private function fecha(mixed $valor): ?string
    {
        return $valor ? Carbon::parse($valor)->toDateString() : null;
    }

    /** @return array<int, int> Años con registros vigentes, del más reciente al más antiguo. */
    private function anios(string $tipo): array
    {
        [$modelo, $columna] = self::TIPOS[$tipo];
        $valores = $modelo::query()->where('anulado', false)->whereNotNull($columna)->distinct()->pluck($columna);

        return $valores->map(fn (mixed $valor): int => $columna === 'ciclo_anio' ? (int) $valor : Carbon::parse($valor)->year)
            ->unique()->sortDesc()->values()->all();
    }
}
