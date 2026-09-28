<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria_eventos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamp('fecha_evento')->nullable();
            $table->uuid('actor_usuario_id')->nullable();
            $table->uuid('usuario_titular_id')->nullable();
            $table->string('accion', 40);
            $table->string('entidad', 60);
            $table->uuid('entidad_id');
            $table->text('datos_antes')->nullable();
            $table->text('datos_despues')->nullable();
            $table->string('ip_origen', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->uuid('request_id')->nullable();
            $table->char('hash_previo', 64)->nullable();
            $table->char('hash_registro', 64);

            $table->foreign('actor_usuario_id', 'fk_13_actor_usuario_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
            $table->foreign('usuario_titular_id', 'fk_13_usuario_titular_id')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_eventos');
    }
};
