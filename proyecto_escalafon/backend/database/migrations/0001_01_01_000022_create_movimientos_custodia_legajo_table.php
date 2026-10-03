<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_custodia_legajo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('legajo_id');
            $table->string('tipo_movimiento', 35);
            $table->string('base_autorizacion', 30)->nullable();
            $table->uuid('autorizado_por');
            $table->string('entidad_destino', 200)->nullable();
            $table->string('numero_documento_sustento', 60)->nullable();
            $table->uuid('sustento_archivo_id')->nullable();
            $table->string('formato_legajo', 10)->nullable();
            $table->string('numero_inventario', 40)->nullable();
            $table->date('fecha_movimiento');
            $table->date('fecha_retorno')->nullable();
            $table->boolean('servidor_comunicado')->default(false);
            $table->date('fecha_comunicacion_servidor')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('legajo_id', 'fk_22_legajo_id')
                ->references('id')->on('legajos')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('autorizado_por', 'fk_22_autorizado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('sustento_archivo_id', 'fk_22_sustento_archivo_id')
                ->references('id')->on('archivos_documento')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_custodia_legajo');
    }
};
