<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos que el legajo no tenía: contacto de emergencia y régimen pensionario en la ficha (sección 01), fecha de
 * fallecimiento del familiar, créditos y modalidad de dictado de las capacitaciones, idiomas y producción intelectual
 * (sección 03, literal h). Todo nullable: los registros existentes no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('contacto_emergencia_nombre', 120)->nullable();
            $table->string('contacto_emergencia_parentesco', 40)->nullable();
            $table->string('contacto_emergencia_telefono', 9)->nullable();
            $table->string('regimen_pensionario', 15)->nullable();
            $table->string('afp_nombre', 60)->nullable();
            $table->string('cuspp', 12)->nullable();
            $table->date('fecha_afiliacion_pension')->nullable();
        });
        Schema::table('familiares', function (Blueprint $table) {
            $table->date('fecha_fallecimiento')->nullable();
        });
        Schema::table('capacitaciones', function (Blueprint $table) {
            $table->decimal('creditos', 5, 1)->nullable();
            $table->string('modalidad_dictado', 15)->nullable();
        });
        Schema::create('idiomas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->uuid('documento_id')->nullable();
            $table->string('idioma', 60);
            $table->string('tipo', 15);
            $table->string('nivel', 15);
            $table->string('institucion', 200)->nullable();
            $table->date('fecha')->nullable();
            $table->uuid('registrado_por');
            $table->boolean('anulado')->default(false);
            $table->text('motivo_anulacion')->nullable();
            $table->timestamps();
            $table->foreign('usuario_id', 'fk_idiomas_usuario_id')->references('id')->on('usuarios')->restrictOnDelete();
            $table->foreign('documento_id', 'fk_idiomas_documento_id')->references('id')->on('documentos_usuario')->restrictOnDelete();
            $table->foreign('registrado_por', 'fk_idiomas_registrado_por')->references('id')->on('usuarios')->restrictOnDelete();
            $table->index(['usuario_id', 'anulado'], 'idiomas_usuario_index');
        });
        Schema::create('producciones_intelectuales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->uuid('documento_id')->nullable();
            $table->string('tipo', 20);
            $table->string('titulo', 300);
            $table->date('fecha_publicacion')->nullable();
            $table->string('editorial_revista', 200)->nullable();
            $table->string('identificador', 80)->nullable();
            $table->string('registro_indecopi', 60)->nullable();
            $table->uuid('registrado_por');
            $table->boolean('anulado')->default(false);
            $table->text('motivo_anulacion')->nullable();
            $table->timestamps();
            $table->foreign('usuario_id', 'fk_produccion_usuario_id')->references('id')->on('usuarios')->restrictOnDelete();
            $table->foreign('documento_id', 'fk_produccion_documento_id')->references('id')->on('documentos_usuario')->restrictOnDelete();
            $table->foreign('registrado_por', 'fk_produccion_registrado_por')->references('id')->on('usuarios')->restrictOnDelete();
            $table->index(['usuario_id', 'anulado'], 'produccion_usuario_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producciones_intelectuales');
        Schema::dropIfExists('idiomas');
        Schema::table('capacitaciones', fn (Blueprint $table) => $table->dropColumn(['creditos', 'modalidad_dictado']));
        Schema::table('familiares', fn (Blueprint $table) => $table->dropColumn('fecha_fallecimiento'));
        Schema::table('usuarios', fn (Blueprint $table) => $table->dropColumn(['contacto_emergencia_nombre', 'contacto_emergencia_parentesco', 'contacto_emergencia_telefono',
            'regimen_pensionario', 'afp_nombre', 'cuspp', 'fecha_afiliacion_pension']));
    }
};
