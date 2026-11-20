<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Escalas de nivel de carrera por ley y grupo ocupacional, propias de cada institución (se mantienen por la API de
 * catálogos; antes fijas en config/legajo.php). Cada entidad aprueba la suya en su CAP/PAP: sobre todo la del D.L. 728
 * varía. Los niveles van del ingreso (orden 1) al máximo. Nada se borra: se desactiva.
 * TODO pendiente de confirmar con el cliente: las escalas por defecto (sobre todo la del D.L. 728).
 *
 * Se cargan los valores por defecto del producto: D.L. 276 (F = ingreso, A = máximo) y D.L. 728 (1 = ingreso).
 * CAS (1057) no tiene nivel de carrera; la Ley 30057 queda sin escala hasta que la institución la defina.
 */
return new class extends Migration
{
    private const POR_DEFECTO = [
        '276' => [
            'profesional' => ['SPF', 'SPE', 'SPD', 'SPC', 'SPB', 'SPA'],
            'tecnico' => ['STF', 'STE', 'STD', 'STC', 'STB', 'STA'],
            'auxiliar' => ['SAF', 'SAE', 'SAD', 'SAC', 'SAB', 'SAA'],
        ],
        '728' => [
            'profesional' => ['P-1', 'P-2', 'P-3', 'P-4', 'P-5', 'P-6'],
            'tecnico' => ['T-1', 'T-2', 'T-3', 'T-4', 'T-5', 'T-6'],
            'auxiliar' => ['A-1', 'A-2', 'A-3', 'A-4', 'A-5', 'A-6'],
        ],
    ];

    public function up(): void
    {
        Schema::create('escalas_nivel_carrera', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('regimen_normativo_id');
            $table->string('grupo_ocupacional', 15);
            // La sigla que figura en la resolución y en el PAP (STB, P-3…): es lo que se guarda en perfil_administrativo.
            $table->string('codigo', 20);
            $table->unsignedSmallInteger('orden')->default(1);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->foreign('regimen_normativo_id')->references('id')->on('regimenes_normativos');
            $table->unique(['regimen_normativo_id', 'grupo_ocupacional', 'codigo']);
        });

        foreach (self::POR_DEFECTO as $codigoLey => $grupos) {
            $ley = DB::table('regimenes_normativos')->where('codigo', $codigoLey)->value('id');
            if (! $ley) {
                continue;
            }
            foreach ($grupos as $grupo => $niveles) {
                foreach ($niveles as $indice => $nivel) {
                    DB::table('escalas_nivel_carrera')->insert(['id' => (string) Str::uuid7(), 'regimen_normativo_id' => $ley, 'grupo_ocupacional' => $grupo,
                        'codigo' => $nivel, 'orden' => $indice + 1, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('escalas_nivel_carrera');
    }
};
