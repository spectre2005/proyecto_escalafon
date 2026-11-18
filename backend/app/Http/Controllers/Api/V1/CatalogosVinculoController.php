<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Puesto;
use App\Models\UnidadOrganica;
use App\Models\VinculoLaboral;
use App\Support\Auditoria;
use App\Support\CatalogosVinculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Configuración de las listas de puestos y unidades orgánicas (solo sistema.administrar). Las listas se llenan solas con
 * lo que se registra en los vínculos (CatalogosVinculo); aquí el administrador agrega, corrige nombres y desactiva.
 * Nunca se borra: un vínculo conserva su puesto (texto) y su unidad aunque se desactiven o renombren.
 */
class CatalogosVinculoController extends Controller
{
    public function puestos(): JsonResponse
    {
        // Uso por puesto: vínculos no anulados cuyo texto coincide (sin mayúsculas ni tildes) y del mismo tipo de personal.
        $usos = VinculoLaboral::query()->where('anulado', false)->get(['tipo_personal', 'nombre_puesto'])
            ->countBy(fn (VinculoLaboral $v): string => $v->tipo_personal.'|'.CatalogosVinculo::normalizar($v->nombre_puesto));

        return response()->json(['data' => Puesto::query()->with('registradoPor:id,nombres,apellido_paterno')->orderBy('nombre')->get()
            ->map(fn (Puesto $p): array => [
                'id' => $p->id, 'nombre' => $p->nombre, 'tipo_personal' => $p->tipo_personal, 'activo' => $p->activo,
                'registrado_por' => $this->nombre($p->registradoPor), 'created_at' => $p->created_at?->format('Y-m-d'),
                'vinculos' => $usos[$p->tipo_personal.'|'.$p->nombre_normalizado] ?? 0,
            ])]);
    }

    public function crearPuesto(Request $request): JsonResponse
    {
        $data = $request->validate(['nombre' => 'required|string|max:150', 'tipo_personal' => 'required|in:docente,administrativo']);
        $this->exigirPuestoUnico($data['nombre'], $data['tipo_personal']);
        $puesto = DB::transaction(function () use ($data, $request): Puesto {
            $puesto = Puesto::query()->create([...$data, 'activo' => true, 'registrado_por' => $request->user()->getAuthIdentifier()]);
            Auditoria::registrar($request, 'catalogo.puesto.crear', 'puestos', $puesto->id, null, null, $puesto->getAttributes());

            return $puesto;
        });

        return response()->json(['data' => $puesto->only(['id', 'nombre', 'tipo_personal', 'activo'])], 201);
    }

    public function actualizarPuesto(Request $request, Puesto $puesto): JsonResponse
    {
        $data = $request->validate(['nombre' => 'sometimes|required|string|max:150', 'activo' => 'sometimes|boolean']);
        if (isset($data['nombre'])) {
            $this->exigirPuestoUnico($data['nombre'], $puesto->tipo_personal, $puesto->id);
        }
        DB::transaction(function () use ($data, $request, $puesto): void {
            $antes = $puesto->getAttributes();
            $puesto->update($data);
            Auditoria::registrar($request, 'catalogo.puesto.corregir', 'puestos', $puesto->id, null, $antes, $puesto->getAttributes());
        });

        return response()->json(['data' => $puesto->only(['id', 'nombre', 'tipo_personal', 'activo'])]);
    }

    public function unidades(): JsonResponse
    {
        return response()->json(['data' => UnidadOrganica::query()->with(['registradoPor:id,nombres,apellido_paterno', 'unidadPadre:id,nombre'])
            ->withCount(['vinculosLaborales as vinculos' => fn ($q) => $q->where('anulado', false)])->orderBy('nombre')->get()
            ->map(fn (UnidadOrganica $u): array => [
                'id' => $u->id, 'nombre' => $u->nombre, 'codigo' => $u->codigo, 'tipo' => $u->tipo, 'activo' => $u->activo,
                'unidad_padre_id' => $u->unidad_padre_id, 'unidad_padre' => $u->unidadPadre?->nombre,
                'registrado_por' => $this->nombre($u->registradoPor), 'created_at' => $u->created_at?->format('Y-m-d'), 'vinculos' => $u->vinculos,
            ])]);
    }

    public function crearUnidad(Request $request): JsonResponse
    {
        $data = $request->validate($this->reglasUnidad(true));
        $this->exigirUnidadUnica($data['nombre']);
        $data['nombre'] = CatalogosVinculo::limpiar($data['nombre']);
        $unidad = DB::transaction(function () use ($data, $request): UnidadOrganica {
            $unidad = UnidadOrganica::query()->create([...$data, 'activo' => true, 'registrado_por' => $request->user()->getAuthIdentifier()]);
            Auditoria::registrar($request, 'catalogo.unidad.crear', 'unidades_organicas', $unidad->id, null, null, $unidad->getAttributes());

            return $unidad;
        });

        return response()->json(['data' => $unidad->only(['id', 'nombre', 'codigo', 'tipo', 'unidad_padre_id', 'activo'])], 201);
    }

    public function actualizarUnidad(Request $request, UnidadOrganica $unidad): JsonResponse
    {
        $data = $request->validate([...$this->reglasUnidad(false), 'activo' => 'sometimes|boolean']);
        if (isset($data['nombre'])) {
            $this->exigirUnidadUnica($data['nombre'], $unidad->id);
            $data['nombre'] = CatalogosVinculo::limpiar($data['nombre']);
        }
        if (array_key_exists('unidad_padre_id', $data) && $data['unidad_padre_id'] !== null) {
            $this->exigirSinCiclo($unidad, $data['unidad_padre_id']);
        }
        DB::transaction(function () use ($data, $request, $unidad): void {
            $antes = $unidad->getAttributes();
            $unidad->update($data);
            Auditoria::registrar($request, 'catalogo.unidad.corregir', 'unidades_organicas', $unidad->id, null, $antes, $unidad->getAttributes());
        });

        return response()->json(['data' => $unidad->only(['id', 'nombre', 'codigo', 'tipo', 'unidad_padre_id', 'activo'])]);
    }

    /** @return array<string, string> */
    private function reglasUnidad(bool $creando): array
    {
        return [
            'nombre' => ($creando ? 'required' : 'sometimes|required').'|string|max:150',
            'codigo' => 'sometimes|nullable|string|max:20',
            'tipo' => 'sometimes|nullable|string|max:30',
            'unidad_padre_id' => 'sometimes|nullable|uuid|exists:unidades_organicas,id',
        ];
    }

    private function exigirPuestoUnico(string $nombre, string $tipo, ?string $excepto = null): void
    {
        $otro = Puesto::query()->where('tipo_personal', $tipo)->where('nombre_normalizado', CatalogosVinculo::normalizar($nombre))
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))->first();
        if ($otro) {
            throw ValidationException::withMessages(['nombre' => "Ya existe el puesto «{$otro->nombre}» para el personal {$tipo}".($otro->activo ? '.' : ' (desactivado): reactívalo en lugar de crear otro.')]);
        }
    }

    private function exigirUnidadUnica(string $nombre, ?string $excepto = null): void
    {
        $otra = CatalogosVinculo::unidadConNombre($nombre, $excepto);
        if ($otra) {
            throw ValidationException::withMessages(['nombre' => "Ya existe la unidad orgánica «{$otra->nombre}»."]);
        }
    }

    /** La unidad de la que depende no puede ser ella misma ni una que dependa de ella. */
    private function exigirSinCiclo(UnidadOrganica $unidad, string $padreId): void
    {
        for ($actual = $padreId, $vueltas = 0; $actual !== null && $vueltas < 100; $vueltas++) {
            if ($actual === $unidad->id) {
                throw ValidationException::withMessages(['unidad_padre_id' => 'Una unidad no puede depender de sí misma ni de una unidad que depende de ella.']);
            }
            $actual = UnidadOrganica::query()->whereKey($actual)->value('unidad_padre_id');
        }
    }

    private function nombre($usuario): ?string
    {
        return $usuario ? trim($usuario->nombres.' '.$usuario->apellido_paterno) : null;
    }
}
