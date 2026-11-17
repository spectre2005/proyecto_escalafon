<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La lectura con IA corre después de responder al navegador (LeerDocumentoConIa): aquí queda su estado y su avance
 * para que la pantalla lo consulte. Las lecturas anteriores ya terminaron: quedan «lista». Sin valores del documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lecturas_ia', function (Blueprint $table) {
            // en_espera | leyendo | interpretando | lista | error
            $table->string('estado', 15)->default('lista');
            $table->unsignedSmallInteger('pagina_actual')->nullable();
            // Páginas que se leerán (hasta escaner_paginas_maximas) y páginas que tiene el PDF.
            $table->unsignedSmallInteger('paginas_leer')->nullable();
            $table->unsignedSmallInteger('paginas_documento')->nullable();
            $table->string('error', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lecturas_ia', function (Blueprint $table) {
            $table->dropColumn(['estado', 'pagina_actual', 'paginas_leer', 'paginas_documento', 'error']);
        });
    }
};
