<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archivos_documento', fn (Blueprint $table) => $table->string('ruta_sin_cifrar_pendiente', 600)->nullable());
    }

    public function down(): void
    {
        Schema::table('archivos_documento', fn (Blueprint $table) => $table->dropColumn('ruta_sin_cifrar_pendiente'));
    }
};
