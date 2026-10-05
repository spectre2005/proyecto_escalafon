<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiencia_laboral', function (Blueprint $table): void {
            $table->string('tipo_vinculo', 20)->nullable();
            $table->boolean('es_ejercicio_profesional')->nullable();
            $table->boolean('es_docencia_universitaria')->nullable();
            $table->index(['usuario_id', 'fecha_inicio', 'fecha_fin'], 'experiencia_usuario_periodo_index');
            $table->index('documento_id', 'experiencia_documento_index');
        });
        Schema::table('documentos_usuario', fn (Blueprint $table) => $table->text('funcion_asignada')->nullable());
    }

    public function down(): void
    {
        Schema::table('documentos_usuario', fn (Blueprint $table) => $table->dropColumn('funcion_asignada'));
        Schema::table('experiencia_laboral', function (Blueprint $table): void {
            $table->dropIndex('experiencia_usuario_periodo_index');
            $table->dropIndex('experiencia_documento_index');
            $table->dropColumn(['tipo_vinculo', 'es_ejercicio_profesional', 'es_docencia_universitaria']);
        });
    }
};
