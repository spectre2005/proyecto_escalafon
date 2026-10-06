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
            Seccion01CatalogoSeeder::class,
            Seccion02CatalogoSeeder::class,
            Seccion03CatalogoSeeder::class,
            Seccion06CatalogoSeeder::class,
            Seccion08CatalogoSeeder::class,
            Seccion05CatalogoSeeder::class,
            Seccion07CatalogoSeeder::class,
            Seccion09CatalogoSeeder::class,
            AdministradorSeeder::class,
            UsuarioSeeder::class,
            EvaluacionDesempenoSeeder::class,
            RequerimientoDocumentoSeeder::class,
        ]);
    }
}
