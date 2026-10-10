<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_fiscalizacion', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('proceso_fiscalizacion_id');
            $table->uuid('documento_id');
            $table->string('entidad_consultada', 200);
            $table->string('medio', 30);
            $table->string('numero_oficio', 40)->nullable();
            $table->date('fecha_envio');
            $table->uuid('oficio_archivo_id')->nullable();
            $table->date('fecha_respuesta')->nullable();
            $table->uuid('documento_respuesta_id')->nullable();
            $table->string('resultado', 15)->default('pendiente');
            $table->text('descripcion_hallazgo')->nullable();
            $table->uuid('registrado_por');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('proceso_fiscalizacion_id', 'fk_53_proceso_fiscalizacion_id')
                ->references('id')->on('procesos_fiscalizacion')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_id', 'fk_53_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('oficio_archivo_id', 'fk_53_oficio_archivo_id')
                ->references('id')->on('archivos_documento')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_respuesta_id', 'fk_53_documento_respuesta_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('registrado_por', 'fk_53_registrado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_fiscalizacion');
    }
};
