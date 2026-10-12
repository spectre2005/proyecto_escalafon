<?php

namespace App\Support;

use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\SeccionLegajo;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Apertura del legajo (art. 3.1): crea el legajo activo y las filas de sus 12 secciones normativas (legajo_secciones,
 * ultimo_folio = 0) en una sola transacción; un solo legajo activo por persona. La sección adicional de legajos pasivos
 * (art. 3.2, "13") es optativa y no se inicializa aquí. La usan la apertura desde la ficha (AperturaLegajoController) y
 * el alta de personal con su primer vínculo (UsuarioController::storeConVinculo).
 */
final class AperturaLegajo
{
    /** @return Legajo|null el legajo abierto, o null si la persona ya tenía uno activo */
    public static function abrir(string $usuario): ?Legajo
    {
        $secciones = range(1, 12);
        abort_unless(SeccionLegajo::query()->whereIn('id', $secciones)->count() === count($secciones), 409,
            'El catálogo de secciones del legajo está incompleto: deben existir las 12 secciones normativas (SeccionesLegajoCatalogoSeeder).');

        return DB::transaction(function () use ($usuario, $secciones): ?Legajo {
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
    }
}
