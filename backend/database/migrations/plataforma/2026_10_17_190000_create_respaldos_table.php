<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de los respaldos (docker/produccion/respaldar.sh y restaurar.sh), para que el panel muestre el último de
 * cada institución. Solo metadatos: el respaldo en sí queda en el servidor (respaldos/) y, cifrado, en la copia externa.
 * - objetivo: slug de la institución, «plataforma» (su base) o «principal» (la base del .env sin institución).
 * - carpeta: nombre de la ejecución (AAAAMMDD-HHMMSS), igual en respaldos/ y en la copia externa.
 * - copia_externa: no_configurada | correcta | fallida. verificacion: restauración de prueba en una base temporal.
 */
return new class extends Migration
{
    protected $connection = 'plataforma';

    public function up(): void
    {
        Schema::connection('plataforma')->create('respaldos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('institucion_id')->nullable();
            $table->string('objetivo', 60);
            $table->string('carpeta', 20);
            $table->string('estado', 20);
            $table->string('base_datos', 63)->nullable();
            $table->unsignedBigInteger('base_bytes')->nullable();
            $table->char('base_sha256', 64)->nullable();
            $table->unsignedBigInteger('archivos_bytes')->nullable();
            $table->char('archivos_sha256', 64)->nullable();
            $table->string('copia_externa', 20)->default('no_configurada');
            $table->string('detalle', 500)->nullable();
            $table->string('verificacion', 20)->nullable();
            $table->timestamp('verificado_en')->nullable();
            $table->string('detalle_verificacion', 500)->nullable();
            $table->timestamps();
            $table->foreign('institucion_id')->references('id')->on('instituciones');
            $table->unique(['objetivo', 'carpeta']);
            $table->index(['institucion_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('plataforma')->dropIfExists('respaldos');
    }
};
