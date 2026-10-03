<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_documento', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->smallInteger('seccion_legajo_id');
            $table->uuid('subseccion_legajo_id')->nullable();
            $table->string('codigo', 40);
            $table->string('nombre', 200);
            $table->text('descripcion')->nullable();
            $table->string('base_normativa', 15)->default('SERVIR');
            $table->boolean('aplica_carrera')->default(true);
            $table->boolean('aplica_plazo_determinado')->default(true);
            $table->boolean('aplica_designado')->default(true);
            $table->boolean('requiere_docente')->default(false);
            $table->boolean('requiere_administrativo')->default(false);
            $table->boolean('requiere_djbr')->default(false);
            $table->boolean('requiere_colegiatura')->default(false);
            $table->boolean('requiere_derechohabientes')->default(false);
            $table->boolean('requiere_periodo_prueba')->default(false);
            $table->boolean('requiere_discapacidad')->default(false);
            $table->boolean('tiene_banderas')->default(false);
            $table->boolean('es_universal')->default(false);
            $table->boolean('obligatorio')->default(true);
            $table->boolean('acredita_perfil_puesto')->default(false);
            $table->boolean('permite_multiples')->default(false);
            $table->boolean('requiere_num_resolucion')->default(false);
            $table->boolean('requiere_fedateo')->default(false);
            $table->boolean('es_dato_sensible')->default(false);
            $table->boolean('es_dato_salud')->default(false);
            $table->boolean('es_hook_universidad')->default(false);
            $table->string('formulario_datos', 40)->nullable();
            $table->smallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('seccion_legajo_id', 'fk_10_seccion_legajo_id')
                ->references('id')->on('secciones_legajo')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('subseccion_legajo_id', 'fk_10_subseccion_legajo_id')
                ->references('id')->on('subsecciones_legajo')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_documento');
    }
};
