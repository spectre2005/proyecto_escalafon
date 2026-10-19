<?php

use Database\Seeders\Seccion12CatalogoSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referencias_legajo_pasivo', function (Blueprint $table): void {
            $table->foreignUuid('legajo_origen_id')->nullable()->constrained('legajos');
            $table->foreignUuid('documento_origen_id')->nullable()->constrained('documentos_usuario');
            $table->text('motivo')->nullable();
            $table->unique(['legajo_id', 'documento_origen_id'], 'referencia_legajo_documento_unica');
        });
        (new Seccion12CatalogoSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('referencias_legajo_pasivo', function (Blueprint $table): void {
            $table->dropUnique('referencia_legajo_documento_unica');
            $table->dropForeign(['legajo_origen_id']);
            $table->dropForeign(['documento_origen_id']);
            $table->dropColumn(['legajo_origen_id', 'documento_origen_id', 'motivo']);
        });
    }
};
