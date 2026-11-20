<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quién corresponde cada proceso de selección: tipo de personal y, si es propio de una ley, esa ley (vacíos = todos).
 * Sin esto, un administrativo podía quedar con «Concurso docente» o un D.L. 276 con «Proceso CAS».
 *
 * Los tres procesos de partida (si conservan su nombre original): Proceso CAS → administrativo, D.L. 1057; Concurso
 * público de méritos → administrativo, cualquier ley; Concurso docente → docente. Los que creó la institución quedan
 * para todos hasta que se les asigne alcance.
 * TODO pendiente de confirmar con el cliente: el alcance de cada proceso (p. ej. si el concurso público vale para docentes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_proceso_seleccion', function (Blueprint $table) {
            $table->string('tipo_personal', 20)->nullable()->after('nombre');
            $table->foreignUuid('regimen_normativo_id')->nullable()->after('tipo_personal')->constrained('regimenes_normativos')->restrictOnDelete();
        });

        $cas = DB::table('regimenes_normativos')->where('codigo', '1057')->value('id');
        foreach ([['Proceso CAS', 'administrativo', $cas], ['Concurso público de méritos', 'administrativo', null], ['Concurso docente', 'docente', null]] as [$nombre, $tipo, $ley]) {
            DB::table('tipos_proceso_seleccion')->where('nombre', $nombre)->whereNull('tipo_personal')
                ->update(['tipo_personal' => $tipo, 'regimen_normativo_id' => $ley, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('tipos_proceso_seleccion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('regimen_normativo_id');
            $table->dropColumn('tipo_personal');
        });
    }
};
