<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vinculo_adendas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vinculo_laboral_id');
            $table->string('numero', 40)->nullable();
            $table->string('motivo', 200)->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->uuid('documento_id')->nullable();
            $table->uuid('registrado_por');
            $table->timestamp('created_at')->nullable();

            $table->foreign('vinculo_laboral_id', 'fk_23_vinculo_laboral_id')
                ->references('id')->on('vinculos_laborales')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_id', 'fk_23_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('registrado_por', 'fk_23_registrado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vinculo_adendas');
    }
};
