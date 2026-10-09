<?php

use Database\Seeders\Seccion10CatalogoSeeder;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        (new SeccionesLegajoCatalogoSeeder)->run();
        (new Seccion10CatalogoSeeder)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // El catálogo se conserva porque puede estar referenciado por documentos y legajos.
    }
};
