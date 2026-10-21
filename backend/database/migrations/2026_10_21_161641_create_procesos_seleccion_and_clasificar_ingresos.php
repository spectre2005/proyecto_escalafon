<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('procesos_seleccion', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('regimen_normativo_id')->constrained('regimenes_normativos')->restrictOnDelete();
            $table->string('codigo', 100);
            $table->unsignedSmallInteger('anio');
            $table->timestamps();
            $table->unique(['regimen_normativo_id', 'anio', 'codigo'], 'procesos_seleccion_codigo_unico');
        });
        Schema::table('vinculos_laborales', function (Blueprint $table): void {
            $table->string('modalidad_ingreso', 30)->nullable();
            $table->string('sustento_ingreso', 500)->nullable();
            $table->foreignUuid('proceso_seleccion_id')->nullable()->constrained('procesos_seleccion')->restrictOnDelete();
        });
        Schema::table('tipos_documento', function (Blueprint $table): void {
            $table->boolean('aplica_plazo_indeterminado')->default(true);
        });
        DB::table('tipos_documento')->where(function ($query): void {
            $query->where('aplica_carrera', false)->orWhere('aplica_plazo_determinado', false)->orWhere('aplica_designado', false);
        })->update(['aplica_plazo_indeterminado' => false]);
        DB::table('tipos_documento')->where('codigo', 'S02_F_CONTRATO')->update(['aplica_plazo_indeterminado' => true]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vinculos_laborales DROP CONSTRAINT IF EXISTS vinculos_laborales_regimen_laboral_tipo');
            DB::statement("ALTER TABLE vinculos_laborales ADD CONSTRAINT vinculos_laborales_regimen_laboral_tipo CHECK (regimen_laboral IS NULL OR regimen_laboral IN ('carrera', 'plazo_determinado', 'plazo_indeterminado', 'designado'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('vinculos_laborales')->where('regimen_laboral', 'plazo_indeterminado')->exists()) {
            throw new RuntimeException('Existen vínculos de plazo indeterminado. No se revierte la migración para conservar su clasificación.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vinculos_laborales DROP CONSTRAINT IF EXISTS vinculos_laborales_regimen_laboral_tipo');
            DB::statement("ALTER TABLE vinculos_laborales ADD CONSTRAINT vinculos_laborales_regimen_laboral_tipo CHECK (regimen_laboral IS NULL OR regimen_laboral IN ('carrera', 'plazo_determinado', 'designado'))");
        }
        Schema::table('vinculos_laborales', function (Blueprint $table): void {
            $table->dropForeign(['proceso_seleccion_id']);
            $table->dropColumn(['modalidad_ingreso', 'sustento_ingreso', 'proceso_seleccion_id']);
        });
        Schema::table('tipos_documento', fn (Blueprint $table) => $table->dropColumn('aplica_plazo_indeterminado'));
        Schema::dropIfExists('procesos_seleccion');
    }
};
