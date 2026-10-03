<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progresiones_carrera', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->uuid('documento_id')->nullable();
            $table->uuid('vinculo_laboral_id');
            $table->string('nivel_anterior', 20)->nullable();
            $table->string('nivel_nuevo', 20);
            $table->date('fecha_efectiva');
            $table->string('numero_resolucion', 60);
            $table->uuid('registrado_por');
            $table->boolean('anulado')->default(false);
            $table->text('motivo_anulacion')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('usuario_id', 'fk_39_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_id', 'fk_39_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('vinculo_laboral_id', 'fk_39_vinculo_laboral_id')
                ->references('id')->on('vinculos_laborales')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('registrado_por', 'fk_39_registrado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progresiones_carrera');
    }
};
