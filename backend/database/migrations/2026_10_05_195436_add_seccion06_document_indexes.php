<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['compensaciones_beneficios', 'tiempo_servicios_reconocidos'] as $nombre) {
            Schema::table($nombre, function (Blueprint $table) use ($nombre): void {
                $table->index('usuario_id', $nombre.'_usuario_idx');
                $table->index('documento_id', $nombre.'_documento_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['compensaciones_beneficios', 'tiempo_servicios_reconocidos'] as $nombre) {
            Schema::table($nombre, function (Blueprint $table) use ($nombre): void {
                $table->dropIndex($nombre.'_usuario_idx');
                $table->dropIndex($nombre.'_documento_idx');
            });
        }
    }
};
