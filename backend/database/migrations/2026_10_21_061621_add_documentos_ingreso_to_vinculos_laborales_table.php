<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vinculos_laborales', function (Blueprint $table): void {
            $table->string('numero_resolucion', 60)->nullable();
            $table->date('fecha_resolucion')->nullable();
            $table->string('numero_contrato', 60)->nullable();
            $table->date('fecha_contrato')->nullable();
            $table->unsignedSmallInteger('anio_proceso')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vinculos_laborales', function (Blueprint $table): void {
            $table->dropColumn(['numero_resolucion', 'fecha_resolucion', 'numero_contrato', 'fecha_contrato', 'anio_proceso']);
        });
    }
};
