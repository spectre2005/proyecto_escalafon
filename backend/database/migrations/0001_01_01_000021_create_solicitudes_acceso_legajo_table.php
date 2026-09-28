<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_acceso_legajo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('legajo_id');
            $table->uuid('solicitante_usuario_id')->nullable();
            $table->string('solicitante_nombre', 200);
            $table->string('solicitante_documento', 20);
            $table->string('entidad_procedencia', 200)->nullable();
            $table->string('tipo_solicitante', 20);
            $table->text('detalle_requerido');
            $table->text('sustento');
            $table->string('forma_entrega', 10);
            $table->boolean('incluye_datos_sensibles')->default(false);
            $table->boolean('compromiso_confidencialidad')->default(false);
            $table->string('estado', 15)->default('pendiente');
            $table->uuid('resuelta_por')->nullable();
            $table->timestamp('fecha_resolucion')->nullable();
            $table->text('motivo_resolucion')->nullable();
            $table->date('vigente_hasta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('legajo_id', 'fk_21_legajo_id')
                ->references('id')->on('legajos')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('solicitante_usuario_id', 'fk_21_solicitante_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('resuelta_por', 'fk_21_resuelta_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_acceso_legajo');
    }
};
