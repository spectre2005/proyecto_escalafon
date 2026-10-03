<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legajo_secciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('legajo_id');
            $table->smallInteger('seccion_legajo_id');
            $table->integer('ultimo_folio')->default(0);
            $table->timestamp('ultima_actualizacion')->nullable();

            $table->foreign('legajo_id', 'fk_17_legajo_id')
                ->references('id')->on('legajos')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('seccion_legajo_id', 'fk_17_seccion_legajo_id')
                ->references('id')->on('secciones_legajo')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legajo_secciones');
    }
};
