<?php

namespace Database\Seeders;

use App\Models\Plataforma\Institucion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Registra la institución local sobre su base existente, sin crear cuentas ni otras bases. */
class InstitucionesPruebaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->principal();
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

        $principal = Institucion::query()->create([
            'nombre' => $nombre, 'slug' => $slug, 'estado' => Institucion::ACTIVA, 'base_datos' => $base,
            'almacenamiento' => 'instituciones/'.$slug, 'aprovisionada_en' => now(),
        ]);
        if ($slug === 'uniq') {
            $principal->forceFill(['modulos_sistema' => ['evaluaciones' => ['activa' => false, 'motivo' => 'UNIQ no utiliza la consulta general de evaluaciones.', 'por' => 'Configuración inicial', 'fecha' => now()->toIso8601String(), 'origen' => 'inicial']]])->save();
        }
        // Su carpeta de archivos, como la crea el aprovisionamiento: el respaldo la exige.
        File::ensureDirectoryExists(storage_path('app/private/instituciones/'.$slug));
        if (! DB::table('institution_settings')->where('id', 1)->exists()) {
            DB::table('institution_settings')->insert(['id' => 1, 'settings' => json_encode(['name' => $nombre], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
