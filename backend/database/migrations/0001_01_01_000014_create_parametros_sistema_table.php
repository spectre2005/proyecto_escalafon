<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametros_sistema', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('clave', 80);
            $table->string('valor', 200);
            $table->string('tipo_dato', 10);
            $table->string('descripcion', 300)->nullable();
            $table->uuid('actualizado_por')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('actualizado_por', 'fk_14_actualizado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametros_sistema');
    }
};
