<?php

use Illuminate\Database\Migrations\Migration;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Database\Seeders\Seccion10CatalogoSeeder;

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
