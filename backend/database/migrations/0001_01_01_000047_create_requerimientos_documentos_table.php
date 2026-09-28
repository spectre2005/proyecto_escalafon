<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requerimientos_documentos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->uuid('tipo_documento_id')->nullable();
            $table->string('origen', 15);
            $table->string('descripcion', 300);
            $table->date('fecha_requerimiento');
            $table->integer('plazo_dias_habiles');
            $table->date('fecha_limite');
            $table->date('prorroga_hasta')->nullable();
            $table->string('estado', 15)->default('abierto');
            $table->uuid('documento_id')->nullable();
            $table->uuid('solicitado_por');
            $table->timestamp('cerrado_en')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('usuario_id', 'fk_47_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('tipo_documento_id', 'fk_47_tipo_documento_id')
                ->references('id')->on('tipos_documento')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_id', 'fk_47_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('solicitado_por', 'fk_47_solicitado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requerimientos_documentos');
    }
};
