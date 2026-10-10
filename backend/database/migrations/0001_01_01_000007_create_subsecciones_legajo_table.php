<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subsecciones_legajo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->smallInteger('seccion_legajo_id');
            $table->string('codigo', 10);
            $table->string('nombre', 150);
            $table->smallInteger('orden')->default(0);

            $table->foreign('seccion_legajo_id', 'fk_07_seccion_legajo_id')
                ->references('id')->on('secciones_legajo')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subsecciones_legajo');
    }
};
