<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora_accesos_legajo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('legajo_id');
            $table->uuid('documento_id')->nullable();
            $table->uuid('usuario_id');
            $table->uuid('solicitud_acceso_id')->nullable();
            $table->string('accion', 15);
            $table->string('ip_origen', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->timestamp('fecha_acceso')->nullable();

            $table->foreign('legajo_id', 'fk_50_legajo_id')
                ->references('id')->on('legajos')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_id', 'fk_50_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('usuario_id', 'fk_50_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('solicitud_acceso_id', 'fk_50_solicitud_acceso_id')
                ->references('id')->on('solicitudes_acceso_legajo')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora_accesos_legajo');
    }
};
