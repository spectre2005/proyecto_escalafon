<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compromiso_documentos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('compromiso_id');
            $table->uuid('tipo_documento_id');
            $table->string('descripcion', 200)->nullable();
            $table->uuid('documento_id')->nullable();
            $table->date('entregado_en')->nullable();

            $table->foreign('compromiso_id', 'fk_24_compromiso_id')
                ->references('id')->on('compromisos_entrega_originales')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('tipo_documento_id', 'fk_24_tipo_documento_id')
                ->references('id')->on('tipos_documento')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_id', 'fk_24_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compromiso_documentos');
    }
};
