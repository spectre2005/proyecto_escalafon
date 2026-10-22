<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conexiones_correo', function (Blueprint $table): void {
            $table->json('alcances')->nullable();
            $table->timestamp('recepcion_revisada_en')->nullable();
            $table->string('recepcion_error', 255)->nullable();
        });
        Schema::create('correos_recibidos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('buzon');
            $table->string('mensaje_id', 128);
            $table->foreignUuid('requerimiento_id')->nullable()->constrained('requerimientos_documentos')->nullOnDelete();
            $table->string('estado', 30);
            $table->string('motivo')->nullable();
            $table->timestamps();
            $table->unique(['buzon', 'mensaje_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correos_recibidos');
        Schema::table('conexiones_correo', function (Blueprint $table): void {
            $table->dropColumn(['alcances', 'recepcion_revisada_en', 'recepcion_error']);
        });
    }
};
