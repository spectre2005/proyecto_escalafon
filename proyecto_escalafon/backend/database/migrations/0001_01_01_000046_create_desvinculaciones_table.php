<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desvinculaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->uuid('documento_id')->nullable();
            $table->uuid('vinculo_laboral_id');
            $table->string('motivo_cese', 25);
            $table->date('fecha_cese');
            $table->string('numero_resolucion', 60)->nullable();
            $table->date('fecha_entrega_cargo')->nullable();
            $table->boolean('informe_gestion_presentado')->default(false);
            $table->date('fecha_liquidacion')->nullable();
            $table->text('observaciones')->nullable();
            $table->uuid('registrado_por');
            $table->boolean('anulado')->default(false);
            $table->text('motivo_anulacion')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('usuario_id', 'fk_46_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_id', 'fk_46_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('vinculo_laboral_id', 'fk_46_vinculo_laboral_id')
                ->references('id')->on('vinculos_laborales')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('registrado_por', 'fk_46_registrado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desvinculaciones');
    }
};
