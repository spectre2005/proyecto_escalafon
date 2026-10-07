<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recorridos_legajo', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('legajo_id')->constrained('legajos');
            $table->foreignUuid('actor_usuario_id')->constrained('usuarios');
            $table->smallInteger('seccion_actual_id')->nullable();
            $table->foreign('seccion_actual_id')->references('id')->on('secciones_legajo');
            $table->json('secciones_omitidas')->default('[]');
            $table->timestamps();
            $table->unique(['legajo_id', 'actor_usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recorridos_legajo');
    }
};
