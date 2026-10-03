<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial_estados_documento', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('documento_usuario_id');
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20);
            $table->uuid('cambiado_por');
            $table->text('motivo')->nullable();
            $table->uuid('observacion_id')->nullable();
            $table->string('ip_origen', 45)->nullable();
            $table->timestamp('fecha_cambio')->nullable();

            $table->foreign('documento_usuario_id', 'fk_54_documento_usuario_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('cambiado_por', 'fk_54_cambiado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('observacion_id', 'fk_54_observacion_id')
                ->references('id')->on('observaciones_documento')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_estados_documento');
    }
};
