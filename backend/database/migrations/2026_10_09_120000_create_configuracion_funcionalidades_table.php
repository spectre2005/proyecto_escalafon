<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado guardado de las funcionalidades configurables (catálogo en config/funcionalidades.php).
     * Solo hay fila cuando alguien cambia el estado: sin fila, la funcionalidad sigue activa.
     */
    public function up(): void
    {
        Schema::create('configuracion_funcionalidades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('codigo', 80)->unique();
            $table->boolean('activa');
            $table->text('motivo');
            $table->uuid('updated_by');
            $table->timestamps();

            $table->foreign('updated_by', 'fk_configuracion_funcionalidades_updated_by')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuracion_funcionalidades');
    }
};
