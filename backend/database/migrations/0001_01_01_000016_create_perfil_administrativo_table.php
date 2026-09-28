<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfil_administrativo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vinculo_laboral_id');
            $table->string('tipo_personal', 15)->default('administrativo');
            $table->string('grupo_ocupacional', 15)->nullable();
            $table->string('nivel_carrera', 20)->nullable();
            $table->string('cargo_estructural', 150)->nullable();
            $table->boolean('es_cargo_confianza')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('vinculo_laboral_id', 'fk_16_vinculo_laboral_id')
                ->references('id')->on('vinculos_laborales')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfil_administrativo');
    }
};
