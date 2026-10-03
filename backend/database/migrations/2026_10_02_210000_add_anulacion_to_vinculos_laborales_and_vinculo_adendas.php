<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anulación con motivo para vínculos laborales y adendas (Directiva, arts. 2.3 y 5.3: no se elimina ni se
 * altera; se anula). Aditiva: las filas existentes quedan con anulado = false y registrado_por = null.
 * vinculo_adendas ya tiene registrado_por (NOT NULL) en el esquema: aquí solo se agregan las columnas de anulación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vinculos_laborales', function (Blueprint $table) {
            $table->boolean('anulado')->default(false);
            $table->text('motivo_anulacion')->nullable();
            $table->uuid('registrado_por')->nullable();

            $table->foreign('registrado_por', 'fk_09_registrado_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });

        Schema::table('vinculo_adendas', function (Blueprint $table) {
            $table->boolean('anulado')->default(false);
            $table->text('motivo_anulacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vinculo_adendas', function (Blueprint $table) {
            $table->dropColumn(['anulado', 'motivo_anulacion']);
        });

        Schema::table('vinculos_laborales', function (Blueprint $table) {
            $table->dropForeign('fk_09_registrado_por');
            $table->dropColumn(['anulado', 'motivo_anulacion', 'registrado_por']);
        });
    }
};
