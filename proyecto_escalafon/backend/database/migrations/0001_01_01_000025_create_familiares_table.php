<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('familiares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->uuid('documento_id')->nullable();
            $table->string('parentesco', 15);
            $table->string('tipo_doc_identidad', 15)->nullable();
            $table->string('numero_doc_identidad', 20)->nullable();
            $table->string('apellidos_nombres', 200);
            $table->date('fecha_nacimiento')->nullable();
            $table->boolean('es_derechohabiente')->default(true);
            $table->uuid('registrado_por');
            $table->boolean('anulado')->default(false);
            $table->text('motivo_anulacion')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('usuario_id', 'fk_25_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_id', 'fk_25_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('registrado_por', 'fk_25_registrado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('familiares');
    }
};
