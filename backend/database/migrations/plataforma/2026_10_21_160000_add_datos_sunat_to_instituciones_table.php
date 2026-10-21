<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos tributarios del RUC de cada institución, tal como los devolvió la consulta (Factiliza) al registrarla o editar
 * su RUC, con la fecha de esa consulta. Son una referencia: no se escriben a mano. Todos opcionales (registro manual,
 * instituciones anteriores o consulta no disponible).
 */
return new class extends Migration
{
    protected $connection = 'plataforma';

    private const COLUMNAS = ['sunat_tipo_contribuyente', 'sunat_estado', 'sunat_condicion', 'sunat_direccion', 'sunat_direccion_completa',
        'sunat_departamento', 'sunat_provincia', 'sunat_distrito', 'sunat_ubigeo', 'sunat_consultado_en'];

    public function up(): void
    {
        Schema::connection('plataforma')->table('instituciones', function (Blueprint $table): void {
            $table->string('sunat_tipo_contribuyente', 150)->nullable();
            $table->string('sunat_estado', 100)->nullable();
            $table->string('sunat_condicion', 100)->nullable();
            $table->string('sunat_direccion', 500)->nullable();
            $table->string('sunat_direccion_completa', 500)->nullable();
            $table->string('sunat_departamento', 100)->nullable();
            $table->string('sunat_provincia', 100)->nullable();
            $table->string('sunat_distrito', 100)->nullable();
            $table->char('sunat_ubigeo', 6)->nullable();
            $table->timestamp('sunat_consultado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('plataforma')->table('instituciones', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNAS);
        });
    }
};
