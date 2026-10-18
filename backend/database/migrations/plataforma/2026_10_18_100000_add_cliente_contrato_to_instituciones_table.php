<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos del cliente y del contrato de cada institución (panel de plataforma). Todos opcionales: las instituciones
 * registradas antes no los tienen. El nombre visible sigue siendo instituciones.nombre; razon_social es el legal.
 */
return new class extends Migration
{
    protected $connection = 'plataforma';

    public function up(): void
    {
        Schema::connection('plataforma')->table('instituciones', function (Blueprint $table): void {
            $table->string('razon_social', 200)->nullable();
            $table->char('ruc', 11)->nullable();
            $table->string('contacto_nombre', 150)->nullable();
            $table->string('contacto_email', 255)->nullable();
            $table->string('contacto_telefono', 20)->nullable();
            $table->string('contrato_numero', 60)->nullable();
            $table->date('contrato_inicio')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('plataforma')->table('instituciones', function (Blueprint $table): void {
            $table->dropColumn(['razon_social', 'ruc', 'contacto_nombre', 'contacto_email', 'contacto_telefono', 'contrato_numero', 'contrato_inicio']);
        });
    }
};
