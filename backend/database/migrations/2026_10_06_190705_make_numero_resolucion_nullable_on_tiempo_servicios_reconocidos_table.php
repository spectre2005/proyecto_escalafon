<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiempo_servicios_reconocidos', function (Blueprint $table): void {
            $table->string('numero_resolucion', 60)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('tiempo_servicios_reconocidos')->whereNull('numero_resolucion')->exists()) {
            throw new RuntimeException('Hay tiempos reconocidos sin número de documento; completa esos números antes de revertir la migración.');
        }

        Schema::table('tiempo_servicios_reconocidos', function (Blueprint $table): void {
            $table->string('numero_resolucion', 60)->nullable(false)->change();
        });
    }
};
