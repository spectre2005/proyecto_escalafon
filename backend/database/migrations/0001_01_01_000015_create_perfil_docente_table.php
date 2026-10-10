<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfil_docente', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vinculo_laboral_id');
            $table->string('tipo_personal', 15)->default('docente');
            $table->string('condicion_docente', 15);
            $table->string('categoria', 10)->nullable();
            $table->string('dedicacion', 25)->nullable();
            $table->string('subtipo_extraordinario', 15)->nullable();
            $table->string('funcion_previa', 25)->nullable();
            $table->string('codigo_renacyt', 30)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('vinculo_laboral_id', 'fk_15_vinculo_laboral_id')
                ->references('id')->on('vinculos_laborales')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfil_docente');
    }
};
