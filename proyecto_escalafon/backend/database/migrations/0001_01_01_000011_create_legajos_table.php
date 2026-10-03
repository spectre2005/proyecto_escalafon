<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legajos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->string('numero_legajo', 20);
            $table->string('estado_legajo', 10)->default('activo');
            $table->string('formato_legajo', 10)->default('digital');
            $table->string('ubicacion', 200)->nullable();
            $table->date('fecha_apertura');
            $table->date('fecha_pasivo')->nullable();
            $table->date('fecha_transferencia_archivo_central')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('usuario_id', 'fk_11_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legajos');
    }
};
