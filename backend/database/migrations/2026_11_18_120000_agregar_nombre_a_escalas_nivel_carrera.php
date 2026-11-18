<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre de cada nivel de carrera, para que se entienda sin conocer la sigla («Servidor Técnico F» para STF). Lo edita
 * cada institución en Configuración. Se completa el de los niveles por defecto: D.L. 276 (S + grupo + letra) y
 * D.L. 728 (grupo + número). Los niveles con otra forma quedan sin nombre (se muestra la sigla).
 */
return new class extends Migration
{
    private const GRUPOS = ['P' => 'Profesional', 'T' => 'Técnico', 'A' => 'Auxiliar'];

    public function up(): void
    {
        Schema::table('escalas_nivel_carrera', function (Blueprint $table) {
            $table->string('nombre', 80)->nullable();
        });
        foreach (DB::table('escalas_nivel_carrera')->whereNull('nombre')->get(['id', 'codigo']) as $nivel) {
            $nombre = match (true) {
                (bool) preg_match('/^S([PTA])([A-F])$/', $nivel->codigo, $m) => 'Servidor '.self::GRUPOS[$m[1]].' '.$m[2],
                (bool) preg_match('/^([PTA])-(\d+)$/', $nivel->codigo, $m) => self::GRUPOS[$m[1]].' nivel '.$m[2],
                default => null,
            };
            if ($nombre !== null) {
                DB::table('escalas_nivel_carrera')->where('id', $nivel->id)->update(['nombre' => $nombre]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('escalas_nivel_carrera', function (Blueprint $table) {
            $table->dropColumn('nombre');
        });
    }
};
