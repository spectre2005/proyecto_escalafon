<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secciones_legajo', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->boolean('es_nucleo_comun')->default(false);
            $table->smallInteger('orden');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secciones_legajo');
    }
};
