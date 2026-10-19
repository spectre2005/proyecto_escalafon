<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificaciones_usuario', function (Blueprint $table): void {
            $table->string('correo_estado', 20)->default('pendiente')->index();
            $table->timestamp('correo_intento_en')->nullable();
            $table->timestamp('correo_enviado_en')->nullable();
            $table->string('correo_error', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notificaciones_usuario', function (Blueprint $table): void {
            $table->dropIndex(['correo_estado']);
            $table->dropColumn(['correo_estado', 'correo_intento_en', 'correo_enviado_en', 'correo_error']);
        });
    }
};
