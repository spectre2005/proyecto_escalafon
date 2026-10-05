<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forma del permiso (Anexo 01, sección 05: "permisos (papeletas, memorándum u otros)"): papeleta, memorandum u otro.
 * Aditiva y nullable: los registros existentes y los que no son permisos quedan en null. Los valores los valida
 * Seccion05Controller (App\Support\Seccion05::FORMAS_PERMISO).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licencias_permisos', function (Blueprint $table) {
            $table->string('forma_permiso', 15)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('licencias_permisos', function (Blueprint $table) {
            $table->dropColumn('forma_permiso');
        });
    }
};
