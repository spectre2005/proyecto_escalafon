<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referencias_legajo_pasivo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('legajo_id');
            $table->uuid('vinculo_laboral_id');
            $table->uuid('tipo_documento_id');
            $table->integer('folio_inicio_pasivo');
            $table->integer('folio_fin_pasivo');
            $table->boolean('fedateado')->default(false);
            $table->date('fecha_fedateo')->nullable();
            $table->boolean('fiscalizado')->default(false);
            $table->date('fecha_fiscalizacion')->nullable();
            $table->uuid('responsable_legajo_id');
            $table->timestamp('created_at')->nullable();

            $table->foreign('legajo_id', 'fk_20_legajo_id')
                ->references('id')->on('legajos')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('vinculo_laboral_id', 'fk_20_vinculo_laboral_id')
                ->references('id')->on('vinculos_laborales')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('tipo_documento_id', 'fk_20_tipo_documento_id')
                ->references('id')->on('tipos_documento')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('responsable_legajo_id', 'fk_20_responsable_legajo_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referencias_legajo_pasivo');
    }
};
