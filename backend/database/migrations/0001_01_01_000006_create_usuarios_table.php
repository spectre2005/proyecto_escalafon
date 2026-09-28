<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tipo_doc_identidad', 15)->default('dni');
            $table->string('numero_doc_identidad', 20);
            $table->string('apellido_paterno', 80);
            $table->string('apellido_materno', 80)->nullable();
            $table->string('nombres', 120);
            $table->date('fecha_nacimiento')->nullable();
            $table->string('nacionalidad', 60)->nullable()->default('Peruana');
            $table->string('estado_civil', 15)->nullable();
            $table->string('domicilio', 250)->nullable();
            $table->uuid('ubigeo_id')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('email_institucional', 255);
            $table->string('password', 255);
            $table->string('tipo_personal', 15);
            $table->string('regimen_laboral', 20);
            $table->uuid('regimen_normativo_id')->nullable();
            $table->uuid('unidad_organica_id')->nullable();
            $table->string('nombre_puesto', 150)->nullable();
            $table->boolean('obligado_djbr')->default(false);
            $table->boolean('exige_colegiatura')->default(false);
            $table->boolean('tiene_periodo_prueba')->default(false);
            $table->boolean('tiene_derechohabientes')->default(false);
            $table->boolean('con_discapacidad')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamp('ultimo_acceso')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('regimen_normativo_id', 'fk_06_regimen_normativo_id')
                ->references('id')->on('regimenes_normativos')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('unidad_organica_id', 'fk_06_unidad_organica_id')
                ->references('id')->on('unidades_organicas')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('ubigeo_id', 'fk_06_ubigeo_id')
                ->references('id')->on('ubigeo')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
