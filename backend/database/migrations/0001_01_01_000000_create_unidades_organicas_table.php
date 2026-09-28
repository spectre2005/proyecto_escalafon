<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_organicas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('codigo', 20);
            $table->string('nombre', 150);
            $table->string('tipo', 30);
            $table->uuid('unidad_padre_id')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::table('unidades_organicas', function (Blueprint $table) {
            $table->foreign('unidad_padre_id', 'fk_00_unidad_padre_id')
                ->references('id')->on('unidades_organicas')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::table('unidades_organicas', function (Blueprint $table) {
            $table->dropForeign('fk_00_unidad_padre_id');
        });

        Schema::dropIfExists('unidades_organicas');
    }
};