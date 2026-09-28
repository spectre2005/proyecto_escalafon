<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuario_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->uuid('rol_id');
            $table->string('ambito_tipo_personal', 15)->default('ambos');
            $table->uuid('unidad_organica_id')->nullable();
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->uuid('asignado_por');
            $table->timestamp('created_at')->nullable();

            $table->foreign('usuario_id', 'fk_08_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('rol_id', 'fk_08_rol_id')
                ->references('id')->on('roles')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('unidad_organica_id', 'fk_08_unidad_organica_id')
                ->references('id')->on('unidades_organicas')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('asignado_por', 'fk_08_asignado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_roles');
    }
};
