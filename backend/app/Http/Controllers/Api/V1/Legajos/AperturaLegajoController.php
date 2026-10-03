<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\SeccionLegajo;
use App\Models\Usuario;
use App\Support\NumeroLegajo;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Abrir el legajo de una persona desde la ficha (permiso legajo.abrir): crea el legajo activo y sus 13
 * secciones (legajo_secciones, con ultimo_folio = 0) en una sola transacción. Un solo legajo activo por persona.
 * TODO pendiente de confirmar con el cliente: formato_legajo (hoy el valor por defecto del esquema, digital),
 * ubicación física y si una persona con legajo pasivo abre uno nuevo (hoy se permite).
 */
class AperturaLegajoController extends Controller
{
    public function store(Request $request, string $usuario): JsonResponse
    {
        abort_if($request->all() !== [], 422, 'La apertura no recibe datos: el número y la fecha los asigna el sistema.');
        $secciones = array_keys(SeccionesLegajoCatalogoSeeder::SECCIONES);
        abort_unless(SeccionLegajo::query()->whereIn('id', $secciones)->count() === count($secciones), 409,
            'El catálogo de secciones del legajo está incompleto: deben existir las 13 secciones (SeccionesLegajoCatalogoSeeder).');

        $legajo = DB::transaction(function () use ($usuario, $secciones): ?Legajo {
            // Bloqueo de la persona: dos aperturas simultáneas no crean dos legajos activos.
            Usuario::query()->whereKey($usuario)->lockForUpdate()->firstOrFail();
            // Bloqueo del correlativo en PostgreSQL (SQLite ya serializa las escrituras).
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(?)', [crc32('numero_legajo')]);
            }
            if (Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->exists()) {
                return null;
            }
            $legajo = Legajo::query()->create([
                'usuario_id' => $usuario, 'numero_legajo' => NumeroLegajo::siguiente(), 'estado_legajo' => 'activo', 'fecha_apertura' => today(),
            ]);
            foreach ($secciones as $seccion) {
                LegajoSeccion::query()->create(['legajo_id' => $legajo->id, 'seccion_legajo_id' => $seccion, 'ultimo_folio' => 0]);
            }

            return $legajo->refresh();
        });
        if (! $legajo) {
            return response()->json(['message' => 'La persona ya tiene un legajo activo.'], 409);
        }

        return response()->json(['data' => [
            ...$legajo->only(['id', 'usuario_id', 'numero_legajo', 'estado_legajo', 'formato_legajo']),
            'fecha_apertura' => $legajo->fecha_apertura->format('Y-m-d'),
            'secciones' => $legajo->legajoSecciones()->count(),
        ]], 201);
    }
}
