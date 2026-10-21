<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('plataforma')->create('identidades_dni', function (Blueprint $table): void {
            $table->string('dni', 8)->primary();
            $table->string('nombres', 120);
            $table->string('apellido_paterno', 80);
            $table->string('apellido_materno', 80)->nullable();
            $table->timestamps();
        });
        Schema::connection('plataforma')->table('administradores_plataforma', function (Blueprint $table): void {
            $table->string('dni', 8)->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('plataforma')->table('administradores_plataforma', function (Blueprint $table): void {
            $table->dropUnique(['dni']);
            $table->dropColumn('dni');
        });
        Schema::connection('plataforma')->dropIfExists('identidades_dni');
    }
};
