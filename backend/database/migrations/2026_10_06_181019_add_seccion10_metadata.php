<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('registros_sst_bienestar', function (Blueprint $table): void {
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->string('entidad', 200)->nullable();
            $table->string('numero_documento', 60)->nullable();
            $table->index(['usuario_id', 'tipo', 'anulado'], 'sst_usuario_tipo_index');
            $table->index('documento_id', 'sst_documento_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registros_sst_bienestar', function (Blueprint $table): void {
            $table->dropIndex('sst_usuario_tipo_index');
            $table->dropIndex('sst_documento_index');
            $table->dropColumn(['fecha_inicio', 'fecha_fin', 'fecha_vencimiento', 'entidad', 'numero_documento']);
        });
    }
};
