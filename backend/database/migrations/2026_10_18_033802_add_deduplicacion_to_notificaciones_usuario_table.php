<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificaciones_usuario', function (Blueprint $table): void {
            $table->string('clave_deduplicacion', 64)->nullable();
            $table->string('enlace', 250)->nullable();
            $table->unique(['usuario_id', 'clave_deduplicacion'], 'avisos_destinatario_clave_unica');
        });
    }

    public function down(): void
    {
        Schema::table('notificaciones_usuario', function (Blueprint $table): void {
            $table->dropUnique('avisos_destinatario_clave_unica');
            $table->dropColumn(['clave_deduplicacion', 'enlace']);
        });
    }
};
