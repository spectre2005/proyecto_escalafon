<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_usuario', function (Blueprint $table) {
            $table->uuid('id');
            $table->primary('id');
            $table->uuid('usuario_id');
            $table->uuid('legajo_id');
            $table->uuid('tipo_documento_id');
            $table->smallInteger('seccion_legajo_id');
            $table->uuid('vinculo_laboral_id')->nullable();
            $table->uuid('archivo_id')->nullable();
            $table->string('modalidad_presentacion', 20)->nullable();
            $table->string('motivo_incorporacion', 300)->nullable();
            $table->integer('folio_inicio')->nullable();
            $table->integer('folio_fin')->nullable();
            $table->date('fecha_recepcion')->nullable();
            $table->uuid('recepcionado_por')->nullable();
            $table->date('fecha_incorporacion')->nullable();
            $table->string('emisor', 200)->nullable();
            $table->date('fecha_emision')->nullable();
            $table->string('titular_nombre', 200)->nullable();
            $table->string('titular_documento', 20)->nullable();
            $table->string('relacion_titular', 20)->default('servidor');
            $table->string('num_resolucion', 60)->nullable();
            $table->string('estado', 20)->default('pendiente');
            $table->boolean('fedateado')->default(false);
            $table->date('fecha_fedateo')->nullable();
            $table->uuid('fedatario_id')->nullable();
            $table->boolean('fiscalizado')->default(false);
            $table->date('fecha_fiscalizacion')->nullable();
            $table->uuid('fiscalizado_por')->nullable();
            $table->uuid('subsana_a_documento_id')->nullable();
            $table->uuid('created_by');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('usuario_id', 'fk_18_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('legajo_id', 'fk_18_legajo_id')
                ->references('id')->on('legajos')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('tipo_documento_id', 'fk_18_tipo_documento_id')
                ->references('id')->on('tipos_documento')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('seccion_legajo_id', 'fk_18_seccion_legajo_id')
                ->references('id')->on('secciones_legajo')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('vinculo_laboral_id', 'fk_18_vinculo_laboral_id')
                ->references('id')->on('vinculos_laborales')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('archivo_id', 'fk_18_archivo_id')
                ->references('id')->on('archivos_documento')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('recepcionado_por', 'fk_18_recepcionado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('fedatario_id', 'fk_18_fedatario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('fiscalizado_por', 'fk_18_fiscalizado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('subsana_a_documento_id', 'fk_18_subsana_a_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('created_by', 'fk_18_created_by')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_usuario');
    }
};
