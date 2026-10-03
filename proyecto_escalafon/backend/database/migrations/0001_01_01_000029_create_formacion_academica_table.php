<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formacion_academica', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->uuid('documento_id')->nullable();
            $table->string('nivel', 30);
            $table->string('denominacion', 200);
            $table->string('institucion', 200);
            $table->string('pais', 60)->default('Perú');
            $table->boolean('es_revalidado')->default(false);
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_obtencion')->nullable();
            $table->string('numero_registro_sunedu', 40)->nullable();
            $table->string('numero_diploma', 40)->nullable();
            $table->uuid('registrado_por');
            $table->boolean('anulado')->default(false);
            $table->text('motivo_anulacion')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('usuario_id', 'fk_29_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_id', 'fk_29_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('registrado_por', 'fk_29_registrado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formacion_academica');
    }
};
