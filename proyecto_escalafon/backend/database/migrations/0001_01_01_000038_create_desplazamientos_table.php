<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desplazamientos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->uuid('documento_id')->nullable();
            $table->string('tipo', 20);
            $table->uuid('unidad_origen_id')->nullable();
            $table->uuid('unidad_destino_id')->nullable();
            $table->string('puesto_destino', 150)->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->string('numero_documento', 60)->nullable();
            $table->uuid('registrado_por');
            $table->boolean('anulado')->default(false);
            $table->text('motivo_anulacion')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('usuario_id', 'fk_38_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('documento_id', 'fk_38_documento_id')
                ->references('id')->on('documentos_usuario')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('unidad_origen_id', 'fk_38_unidad_origen_id')
                ->references('id')->on('unidades_organicas')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('unidad_destino_id', 'fk_38_unidad_destino_id')
                ->references('id')->on('unidades_organicas')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('registrado_por', 'fk_38_registrado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desplazamientos');
    }
};
