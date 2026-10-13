<?php

namespace App\Support\Plataforma;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Comprobaciones (solo lectura) de que una institución está lista para operar. Las usan el comando
 * plataforma:verificar-aprovisionamiento (preparación manual) y el aprovisionamiento automático.
 *
 * - Base: existe en el servidor PostgreSQL institucional y tiene el esquema migrado (migrations e institution_settings).
 * - Almacenamiento: la carpeta storage/app/private/{almacenamiento} existe y se puede escribir.
 */
final class VerificacionAprovisionamiento
{
    /** @return array{bool, string} */
    public static function base(string $base): array
    {
        // La conexión «pgsql» describe el servidor PostgreSQL institucional; solo cambia la base.
        $plantilla = config('database.connections.pgsql');
        if (! is_array($plantilla) || ($plantilla['driver'] ?? null) !== 'pgsql') {
            return [false, 'solo se verifica contra el servidor PostgreSQL institucional.'];
        }
        config(['database.connections.verificacion_institucion' => [...$plantilla, 'database' => $base]]);
        try {
            $listo = Schema::connection('verificacion_institucion')->hasTable('migrations') && Schema::connection('verificacion_institucion')->hasTable('institution_settings');

            return $listo ? [true, "la base {$base} existe y tiene el esquema institucional."] : [false, "la base {$base} existe pero no tiene el esquema institucional migrado."];
        } catch (Throwable) {
            return [false, "no se pudo conectar a la base {$base} (¿existe?)."];
        } finally {
            DB::purge('verificacion_institucion');
        }
    }

    /** @return array{bool, string} */
    public static function almacenamiento(string $ruta): array
    {
        $carpeta = storage_path('app/private/'.$ruta);

        return is_dir($carpeta) && is_writable($carpeta) ? [true, "la carpeta {$ruta} existe y se puede escribir."] : [false, "falta la carpeta {$ruta} o no se puede escribir."];
    }
}
