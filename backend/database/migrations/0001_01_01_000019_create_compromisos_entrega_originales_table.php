<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compromisos_entrega_originales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->uuid('legajo_id');
            $table->text('motivo_impedimento');
            $table->date('fecha_compromiso');
            $table->date('fecha_limite');
            $table->string('estado', 15)->default('vigente');
            $table->date('fecha_cumplimiento')->nullable();
            $table->uuid('archivo_id')->nullable();
            $table->uuid('registrado_por');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('usuario_id', 'fk_19_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('legajo_id', 'fk_19_legajo_id')
                ->references('id')->on('legajos')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('archivo_id', 'fk_19_archivo_id')
                ->references('id')->on('archivos_documento')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('registrado_por', 'fk_19_registrado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compromisos_entrega_originales');
    }
};
