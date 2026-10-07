<?php

use Database\Seeders\Seccion01CatalogoSeeder;
use Database\Seeders\Seccion02CatalogoSeeder;
use Database\Seeders\Seccion05CatalogoSeeder;
use Database\Seeders\Seccion07CatalogoSeeder;
use Database\Seeders\Seccion09CatalogoSeeder;
use Database\Seeders\Seccion11CatalogoSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ([
                Seccion01CatalogoSeeder::class,
                Seccion02CatalogoSeeder::class,
                Seccion05CatalogoSeeder::class,
                Seccion07CatalogoSeeder::class,
                Seccion09CatalogoSeeder::class,
                Seccion11CatalogoSeeder::class,
            ] as $seeder) {
                (new $seeder)->run();
            }
        });
    }

    public function down(): void
    {
        // Keep existing catalogs and references from documents and personnel records intact.
    }
};
