<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones_usuario', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->string('tipo', 30);
            $table->string('titulo', 150);
            $table->text('mensaje');
            $table->uuid('documento_id')->nullable();
            $table->uuid('observacion_id')->nullable();
            $table->uuid('requerimiento_id')->nullable();
            $table->boolean('leida')->default(false);
            $table->timestamp('fecha_lectura')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('usuario_id', 'fk_55_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_id', 'fk_55_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('observacion_id', 'fk_55_observacion_id')
                ->references('id')->on('observaciones_documento')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('requerimiento_id', 'fk_55_requerimiento_id')
                ->references('id')->on('requerimientos_documentos')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones_usuario');
    }
};
