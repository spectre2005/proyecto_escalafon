<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recursos_impugnatorios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sancion_id');
            $table->uuid('documento_id');
            $table->date('fecha');
            $table->string('resultado', 15);
            $table->uuid('registrado_por');
            $table->timestamps();

            $table->foreign('sancion_id', 'fk_recursos_impugnatorios_sancion')
                ->references('id')->on('sanciones')->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_id', 'fk_recursos_impugnatorios_documento')
                ->references('id')->on('documentos_usuario')->onUpdate('no action')->onDelete('no action');
            $table->foreign('registrado_por', 'fk_recursos_impugnatorios_registrado_por')
                ->references('id')->on('usuarios')->onUpdate('no action')->onDelete('no action');
            $table->index(['sancion_id', 'fecha'], 'idx_recursos_impugnatorios_sancion_fecha');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('recursos_impugnatorios') && DB::table('recursos_impugnatorios')->exists()) {
            throw new RuntimeException('Hay recursos impugnatorios registrados; la tabla no puede retirarse.');
        }

        Schema::dropIfExists('recursos_impugnatorios');
    }
};
