<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UnidadOrganicaSeeder::class,
            CatalogosInstitucionSeeder::class,
            AdministradorSeeder::class,
            UsuarioSeeder::class,
            EvaluacionDesempenoSeeder::class,
            RequerimientoDocumentoSeeder::class,
        ]);
    }
}
