<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A quién se ofrece un tipo de documento para cargar: ambos, docente o administrativo. Es aplicabilidad, distinta de
     * requiere_docente / requiere_administrativo (a quién se exige): una maestría se exige al docente, pero un
     * administrativo también puede tenerla. Por defecto, ambos: nada cambia hasta clasificar el catálogo.
     */
    public function up(): void
    {
        Schema::table('tipos_documento', function (Blueprint $table) {
            $table->string('aplica_tipo_personal', 20)->default('ambos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tipos_documento', function (Blueprint $table) {
            $table->dropColumn('aplica_tipo_personal');
        });
    }
};
