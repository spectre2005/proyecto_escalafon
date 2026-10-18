<?php

namespace Database\Seeders;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\Aprovisionamiento;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Instituciones de prueba del panel (solo local/testing), iguales en todos los equipos que clonan el proyecto:
 * - La principal (PLATAFORMA_PRUEBA_SLUG y PLATAFORMA_PRUEBA_NOMBRE) sobre la base recién sembrada, con su
 *   administrador de AdministradorSeeder.
 * - Dos más, aprovisionadas como desde el panel (base propia, catálogos y administrador), con ADMIN_PASSWORD fija.
 * Las que ya existen no se tocan: en un equipo con instituciones propias, se respetan.
 */
class InstitucionesPruebaSeeder extends Seeder
{
    /** @var list<array{slug: string, nombre: string}> */
    private const ADICIONALES = [
        ['slug' => 'uno', 'nombre' => 'Universidad de Prueba Uno'],
        ['slug' => 'dos', 'nombre' => 'Universidad de Prueba Dos'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->principal();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::ADICIONALES as $datos) {
            $this->adicional($datos['slug'], $datos['nombre']);
        }
    }

    private function principal(): void
    {
        $slug = mb_strtolower(trim((string) config('plataforma.institucion_prueba.slug')));
        $nombre = trim((string) config('plataforma.institucion_prueba.nombre'));
        $base = (string) DB::connection()->getDatabaseName();
        if ($slug === '' || $nombre === '' || ! preg_match(Institucion::PATRON_SLUG, $slug) || ! preg_match(Institucion::PATRON_BASE_DATOS, $base)) {
            $this->command?->warn('InstitucionesPruebaSeeder: define PLATAFORMA_PRUEBA_SLUG y PLATAFORMA_PRUEBA_NOMBRE en backend/.env (y una base PostgreSQL). No se registró la institución principal.');

            return;
        }
        // Una base pertenece a una sola institución: si ya está vinculada (p. ej. a mano en un equipo), se respeta.
        $vinculada = Institucion::query()->where('base_datos', $base)->value('slug');
        if ($vinculada !== null || Institucion::query()->where('slug', $slug)->exists()) {
            return;
        }

        Institucion::query()->create([
            'nombre' => $nombre, 'slug' => $slug, 'estado' => Institucion::ACTIVA, 'base_datos' => $base,
            'almacenamiento' => 'instituciones/'.$slug, 'aprovisionada_en' => now(),
        ]);
        // Su carpeta de archivos, como la crea el aprovisionamiento: el respaldo la exige.
        File::ensureDirectoryExists(storage_path('app/private/instituciones/'.$slug));
        if (! DB::table('institution_settings')->where('id', 1)->exists()) {
            DB::table('institution_settings')->insert(['id' => 1, 'settings' => json_encode(['name' => $nombre], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function adicional(string $slug, string $nombre): void
    {
        $password = (string) config('auth.administrador_inicial.password');
        if ($password === '' || Institucion::query()->where('slug', $slug)->exists()) {
            return;
        }

        $base = 'escalafon_'.$slug;
        $email = "admin.{$slug}@demo.test";
        $institucion = Institucion::query()->create([
            'nombre' => $nombre, 'slug' => $slug, 'estado' => Institucion::REGISTRADA, 'base_datos' => $base, 'almacenamiento' => 'instituciones/'.$slug,
        ]);
        try {
            Aprovisionamiento::ejecutar($institucion->id, ['nombres' => 'Administrador', 'apellido_paterno' => $nombre, 'email' => $email], null, null);
        } catch (Throwable $error) {
            $institucion->delete();
            $this->command?->warn("InstitucionesPruebaSeeder: no se aprovisionó «{$slug}»: {$error->getMessage()}");

            return;
        }

        // Clave fija de prueba en lugar de la temporal, para que todos entren con la misma.
        config(['database.connections.prueba_institucion' => [...config('database.connections.pgsql'), 'database' => $base]]);
        try {
            DB::connection('prueba_institucion')->table('usuarios')->where('email_institucional', $email)
                ->update(['password' => Hash::make($password), 'debe_cambiar_password' => false]);
        } finally {
            DB::purge('prueba_institucion');
        }
        $institucion->update(['estado' => Institucion::ACTIVA]);
    }
}
