<?php

namespace App\Support\Plataforma;

use App\Models\Plataforma\Institucion;
use Database\Seeders\CatalogosInstitucionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Aprovisionamiento automático de una institución registrada (una base por cliente): crea su base en el servidor
 * PostgreSQL institucional, la migra, carga los catálogos comunes (CatalogosInstitucionSeeder, sin datos de ejemplo),
 * crea su primer administrador con una contraseña temporal (debe cambiarla al ingresar), guarda su nombre en
 * institution_settings, crea su carpeta de PDF y lo comprueba todo (VerificacionAprovisionamiento) antes de marcarla
 * aprovisionada. No la activa.
 *
 * Nunca toca lo que ya existía: si la base ya existe, o la carpeta tiene archivos, se rechaza. Si algo falla a mitad,
 * se retira solo lo creado en esta ejecución (base y carpeta) y queda auditado el fallo. La fila de la institución se
 * bloquea durante todo el proceso para que no corra dos veces a la vez.
 */
final class Aprovisionamiento
{
    private const SERVIDOR = 'aprovisionamiento_servidor';

    private const BASE = 'aprovisionamiento_base';

    /**
     * @param  array{nombres: string, apellido_paterno: string, email: string}  $administrador  primer administrador (jefe de la ORH)
     * @return string contraseña temporal del primer administrador: no se guarda en ningún otro lugar
     */
    public static function ejecutar(string $institucionId, array $administrador, ?Request $request, ?string $operador): string
    {
        try {
            return DB::connection('plataforma')->transaction(function () use ($institucionId, $administrador, $request, $operador): string {
                $institucion = Institucion::query()->lockForUpdate()->findOrFail($institucionId);
                self::comprobarEstado($institucion);
                $clave = self::preparar($institucion, $administrador);
                $institucion->update(['aprovisionada_en' => now()]);
                AuditoriaOperaciones::registrar('institucion.aprovisionada', $request, $operador, $institucion->id, null,
                    ['base_datos' => $institucion->base_datos, 'almacenamiento' => $institucion->almacenamiento, 'administrador' => $administrador['email']],
                    'Aprovisionamiento automático.');

                return $clave;
            });
        } catch (AprovisionamientoRechazado $rechazo) {
            throw $rechazo;
        } catch (Throwable $error) {
            AuditoriaOperaciones::registrar('institucion.aprovisionamiento_fallido', $request, $operador, $institucionId, null, null,
                mb_substr('Se retiró lo creado. Error: '.$error->getMessage(), 0, 500));
            throw $error;
        }
    }

    private static function comprobarEstado(Institucion $institucion): void
    {
        $plantilla = config('database.connections.pgsql');
        $motivo = match (true) {
            $institucion->aprovisionada() => 'La institución ya está aprovisionada.',
            $institucion->estado !== Institucion::REGISTRADA => 'Solo se aprovisiona una institución registrada.',
            ! preg_match(Institucion::PATRON_BASE_DATOS, $institucion->base_datos) => 'El nombre de su base de datos no es válido.',
            ! preg_match(Institucion::PATRON_ALMACENAMIENTO, $institucion->almacenamiento) => 'Su almacenamiento no es válido.',
            ! is_array($plantilla) || ($plantilla['driver'] ?? null) !== 'pgsql' => 'Solo se aprovisiona en el servidor PostgreSQL institucional.',
            default => null,
        };
        if ($motivo === null) {
            config(['database.connections.'.self::SERVIDOR => [...$plantilla, 'database' => 'postgres']]);
            $existe = DB::connection(self::SERVIDOR)->selectOne('select 1 from pg_database where datname = ?', [$institucion->base_datos]) !== null;
            $carpeta = storage_path('app/private/'.$institucion->almacenamiento);
            $motivo = match (true) {
                $existe => "La base {$institucion->base_datos} ya existe. Si se preparó a mano, verifícala con plataforma:verificar-aprovisionamiento.",
                is_dir($carpeta) && File::files($carpeta) !== [] => "La carpeta {$institucion->almacenamiento} ya tiene archivos.",
                default => null,
            };
        }
        if ($motivo !== null) {
            throw new AprovisionamientoRechazado($motivo);
        }
    }

    /** Crea base, esquema, catálogos, administrador y carpeta. Si algo falla, retira lo creado aquí. */
    private static function preparar(Institucion $institucion, array $administrador): string
    {
        $carpeta = storage_path('app/private/'.$institucion->almacenamiento);
        $creoBase = false;
        $creoCarpeta = false;
        try {
            // El nombre ya cumple PATRON_BASE_DATOS (solo [a-z0-9_]): es seguro entre comillas dobles.
            DB::connection(self::SERVIDOR)->statement('CREATE DATABASE "'.$institucion->base_datos.'"');
            $creoBase = true;
            config(['database.connections.'.self::BASE => [...config('database.connections.pgsql'), 'database' => $institucion->base_datos]]);
            self::artisan('migrate', ['--database' => self::BASE, '--force' => true]);
            self::artisan('db:seed', ['--class' => CatalogosInstitucionSeeder::class, '--database' => self::BASE, '--force' => true]);
            $clave = self::administrador($institucion, $administrador);
            if (! is_dir($carpeta)) {
                File::ensureDirectoryExists($carpeta);
                $creoCarpeta = true;
            }
            foreach ([VerificacionAprovisionamiento::base($institucion->base_datos), VerificacionAprovisionamiento::almacenamiento($institucion->almacenamiento)] as [$ok, $detalle]) {
                if (! $ok) {
                    throw new RuntimeException("La verificación final falló: {$detalle}");
                }
            }

            return $clave;
        } catch (Throwable $error) {
            DB::purge(self::BASE);
            if ($creoBase) {
                DB::connection(self::SERVIDOR)->statement('DROP DATABASE IF EXISTS "'.$institucion->base_datos.'" WITH (FORCE)');
            }
            if ($creoCarpeta) {
                File::deleteDirectory($carpeta);
            }
            throw $error;
        } finally {
            DB::purge(self::BASE);
            DB::purge(self::SERVIDOR);
        }
    }

    /** @param array<string, mixed> $parametros */
    private static function artisan(string $comando, array $parametros): void
    {
        if (Artisan::call($comando, $parametros) !== 0) {
            throw new RuntimeException("Falló {$comando}: ".mb_substr(trim(Artisan::output()), -300));
        }
    }

    /**
     * Primer administrador institucional (rol ADMIN y contraseña temporal) y nombre de la institución, en la base nueva. La
     * conexión por defecto apunta a ella mientras tanto, para que los modelos y sus eventos escriban ahí.
     */
    private static function administrador(Institucion $institucion, array $datos): string
    {
        $anterior = DB::getDefaultConnection();
        DB::setDefaultConnection(self::BASE);
        try {
            return DB::transaction(function () use ($institucion, $datos): string {
                [$admin, $clave] = AdministradoresInstitucion::crear($datos);
                DB::table('institution_settings')->insert(['id' => 1, 'settings' => json_encode(['name' => $institucion->nombre], JSON_THROW_ON_ERROR),
                    'updated_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);

                return $clave;
            });
        } finally {
            DB::setDefaultConnection($anterior);
        }
    }
}
