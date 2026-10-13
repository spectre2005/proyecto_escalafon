<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuenta con contraseña temporal (p. ej. el primer administrador que crea el aprovisionamiento): hasta cambiarla,
     * solo puede consultar su sesión, cerrarla o cambiar la contraseña (ExigeCambioPassword). Por defecto, no.
     */
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->boolean('debe_cambiar_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('debe_cambiar_password');
        });
    }
};
