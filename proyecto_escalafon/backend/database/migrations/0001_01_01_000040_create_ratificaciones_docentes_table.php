<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratificaciones_docentes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->uuid('documento_id')->nullable();
            $table->uuid('perfil_docente_id');
            $table->string('tipo', 15);
            $table->string('categoria_anterior', 10)->nullable();
            $table->string('categoria_nueva', 10);
            $table->string('numero_resolucion', 60);
            $table->date('fecha_resolucion');
            $table->date('fecha_inicio_periodo');
            $table->date('fecha_vencimiento_periodo');
            $table->string('resultado', 15)->default('pendiente');
            $table->uuid('registrado_por');
            $table->boolean('anulado')->default(false);
            $table->text('motivo_anulacion')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('usuario_id', 'fk_40_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_id', 'fk_40_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('perfil_docente_id', 'fk_40_perfil_docente_id')
                ->references('id')->on('perfil_docente')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('registrado_por', 'fk_40_registrado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratificaciones_docentes');
    }
};
