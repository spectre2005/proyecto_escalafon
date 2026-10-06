<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['usuarios', 'vinculos_laborales'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $table) => $table->boolean('exige_estudios_basicos')->default(false));
        }
        Schema::table('tipos_documento', fn (Blueprint $table) => $table->boolean('requiere_estudios_basicos')->default(false));
        Schema::table('documentos_usuario', function (Blueprint $table): void {
            $table->string('nombre', 200)->nullable();
            $table->index(['legajo_id', 'seccion_legajo_id', 'tipo_documento_id'], 'documentos_seccion_tipo_index');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_usuario', function (Blueprint $table): void {
            $table->dropIndex('documentos_seccion_tipo_index');
            $table->dropColumn('nombre');
        });
        Schema::table('tipos_documento', fn (Blueprint $table) => $table->dropColumn('requiere_estudios_basicos'));
        foreach (['usuarios', 'vinculos_laborales'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $table) => $table->dropColumn('exige_estudios_basicos'));
        }
    }
};
