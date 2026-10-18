<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contraseña temporal de un superadministrador (la da otro al crear la cuenta o restablecerla): hasta cambiarla solo
 * puede usar su sesión, cerrarla y cambiar la contraseña. Las cuentas existentes quedan sin la marca.
 */
return new class extends Migration
{
    protected $connection = 'plataforma';

    public function up(): void
    {
        Schema::connection('plataforma')->table('administradores_plataforma', function (Blueprint $table): void {
            $table->boolean('debe_cambiar_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::connection('plataforma')->table('administradores_plataforma', function (Blueprint $table): void {
            $table->dropColumn('debe_cambiar_password');
        });
    }
};
