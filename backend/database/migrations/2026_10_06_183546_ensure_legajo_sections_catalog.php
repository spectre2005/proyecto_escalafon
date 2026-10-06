<?php

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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Las secciones fijas se conservan: pueden estar referenciadas por legajos y documentos.
    }
};
