<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vinculos_laborales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->string('tipo_personal', 15);
            $table->string('regimen_laboral', 20);
            $table->uuid('regimen_normativo_id');
            $table->uuid('unidad_organica_id')->nullable();
            $table->string('nombre_puesto', 150);
            $table->boolean('obligado_djbr')->default(false);
            $table->boolean('exige_colegiatura')->default(false);
            $table->boolean('tiene_periodo_prueba')->default(false);
            $table->string('proceso_seleccion', 100)->nullable();
            $table->date('fecha_resultado_seleccion')->nullable();
            $table->string('numero_documento_vinculo', 60)->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin_prevista')->nullable();
            $table->date('fecha_cese')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('usuario_id', 'fk_09_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('regimen_normativo_id', 'fk_09_regimen_normativo_id')
                ->references('id')->on('regimenes_normativos')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('unidad_organica_id', 'fk_09_unidad_organica_id')
                ->references('id')->on('unidades_organicas')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vinculos_laborales');
    }
};
