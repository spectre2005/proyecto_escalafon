<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada lectura de un PDF con IA y qué hizo después la persona con cada dato propuesto (aceptado, corregido, borrado…).
 * Sirve para medir cuánto acierta el lector por campo. Nunca guarda valores ni citas del documento: solo nombres de
 * campos y su resultado (el contenido se queda en el legajo, como en la auditoría de documento.lectura_ia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecturas_ia', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id')->index();
            $table->uuid('tipo_documento_id')->index();
            $table->uuid('registrado_por')->index();
            $table->string('modelo', 80);
            $table->decimal('segundos', 6, 1)->nullable();
            // coincide | coincide_nombre | dni_distinto | distinto | sin_datos (TitularDocumento).
            $table->string('titular', 20)->nullable();
            $table->json('campos_pedidos');
            $table->json('campos_propuestos');
            $table->json('campos_sugeridos');
            // {campo: aceptado | corregido | borrado | no_detectado | sugerencia_usada}; null mientras no se guarde el formulario.
            $table->json('resultado')->nullable();
            $table->timestamp('evaluado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecturas_ia');
    }
};
