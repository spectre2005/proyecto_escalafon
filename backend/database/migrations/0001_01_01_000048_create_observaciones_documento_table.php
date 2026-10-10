<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observaciones_documento', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('documento_id');
            $table->string('tipo_observacion', 30);
            $table->text('descripcion');
            $table->string('estado', 15)->default('abierta');
            $table->uuid('registrada_por');
            $table->timestamp('fecha_registro')->nullable();
            $table->date('plazo_subsanacion');
            $table->date('prorroga_hasta')->nullable();
            $table->uuid('documento_subsanacion_id')->nullable();
            $table->timestamp('fecha_subsanacion')->nullable();
            $table->uuid('verificada_por')->nullable();
            $table->text('comentario_cierre')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('documento_id', 'fk_48_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('registrada_por', 'fk_48_registrada_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_subsanacion_id', 'fk_48_documento_subsanacion_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('verificada_por', 'fk_48_verificada_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observaciones_documento');
    }
};
