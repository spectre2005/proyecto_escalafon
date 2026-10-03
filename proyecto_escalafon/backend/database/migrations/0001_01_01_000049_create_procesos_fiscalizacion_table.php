<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procesos_fiscalizacion', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->uuid('legajo_id');
            $table->string('tipo_muestra', 25);
            $table->date('fecha_vinculacion');
            $table->date('fecha_inicio');
            $table->date('fecha_limite');
            $table->date('fecha_fin')->nullable();
            $table->string('estado', 15)->default('abierto');
            $table->uuid('responsable_id');
            $table->uuid('informe_documento_id')->nullable();
            $table->text('conclusiones')->nullable();
            $table->date('comunicado_procuraduria_en')->nullable();
            $table->date('comunicado_stpad_en')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('usuario_id', 'fk_49_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('legajo_id', 'fk_49_legajo_id')
                ->references('id')->on('legajos')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('responsable_id', 'fk_49_responsable_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('informe_documento_id', 'fk_49_informe_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procesos_fiscalizacion');
    }
};
