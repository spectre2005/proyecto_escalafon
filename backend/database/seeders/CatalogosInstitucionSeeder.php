<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Catálogos comunes a toda institución, sin datos de ejemplo: regímenes normativos, ubigeo, secciones del legajo y
 * tipos de documento de cada sección. Es lo que recibe una base nueva al aprovisionarla desde la plataforma. El
 * organigrama (UnidadOrganicaSeeder) no va aquí: es propio de cada institución.
 */
class CatalogosInstitucionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RegimenNormativoSeeder::class,
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
            Seccion10CatalogoSeeder::class,
            Seccion11CatalogoSeeder::class,
        ]);
    }
}
