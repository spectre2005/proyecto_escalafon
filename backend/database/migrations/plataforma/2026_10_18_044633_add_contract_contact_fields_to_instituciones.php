<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'plataforma';

    public function up(): void
    {
        Schema::connection('plataforma')->table('instituciones', function (Blueprint $table): void {
            $table->string('razon_social', 255)->nullable();
            $table->string('ruc', 11)->nullable();
            $table->string('contacto_nombre', 150)->nullable();
            $table->string('contacto_email', 255)->nullable();
            $table->string('contacto_telefono', 30)->nullable();
            $table->string('numero_contrato', 100)->nullable();
            $table->date('fecha_inicio_contrato')->nullable();
            $table->unsignedInteger('version_contrato')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection('plataforma')->table('instituciones', fn (Blueprint $table) => $table->dropColumn(['razon_social', 'ruc', 'contacto_nombre', 'contacto_email', 'contacto_telefono', 'numero_contrato', 'fecha_inicio_contrato', 'version_contrato']));
    }
};
