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
            UbigeoSeeder::class,
            SeccionesLegajoCatalogoSeeder::class,
            Seccion02CatalogoSeeder::class,
            Seccion03CatalogoSeeder::class,
            Seccion06CatalogoSeeder::class,
            AdministradorSeeder::class,
            UsuarioSeeder::class,
            EvaluacionDesempenoSeeder::class,
            RequerimientoDocumentoSeeder::class,
        ]);
    }
}
