<?php

use Database\Seeders\Seccion02CatalogoSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        (new Seccion02CatalogoSeeder)->incorporarResolucionDocente();
    }

    public function down(): void
    {
        DB::table('tipos_documento')->where('codigo', Seccion02CatalogoSeeder::RESOLUCION_DOCENTE)->update(['activo' => false]);
    }
};
