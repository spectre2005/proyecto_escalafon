<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLAS = ['vinculos_laborales', 'inducciones', 'periodos_prueba', 'licencias_permisos', 'periodos_vacacionales', 'goces_vacacionales', 'evaluaciones_desempeno', 'progresiones_carrera', 'ratificaciones_docentes', 'desplazamientos', 'controversias', 'afiliaciones', 'desvinculaciones', 'requerimientos_documentos', 'declaraciones_verificaciones'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasColumn($tabla, 'legajo_id')) {
                Schema::table($tabla, fn (Blueprint $columnas) => $columnas->foreignUuid('legajo_id')->nullable()->index()->constrained('legajos')->restrictOnDelete());
            }
            DB::table($tabla)->whereNull('legajo_id')->orderBy('id')->chunkById(200, function ($registros) use ($tabla): void {
                foreach ($registros as $registro) {
                    $candidatos = collect();
                    if (isset($registro->documento_id)) {
                        $candidatos = $candidatos->merge(DB::table('documentos_usuario')->where('id', $registro->documento_id)->where('usuario_id', $registro->usuario_id)->pluck('legajo_id'));
                    }
                    if ($tabla === 'vinculos_laborales') {
                        $candidatos = $candidatos->merge(DB::table('documentos_usuario')->where('vinculo_laboral_id', $registro->id)->where('usuario_id', $registro->usuario_id)->pluck('legajo_id'));
                    }
                    foreach (['vinculo_laboral_id' => 'vinculos_laborales', 'periodo_vacacional_id' => 'periodos_vacacionales'] as $campo => $padre) {
                        if (isset($registro->{$campo})) {
                            $candidatos = $candidatos->merge(DB::table($padre)->where('id', $registro->{$campo})->where('usuario_id', $registro->usuario_id)->pluck('legajo_id'));
                        }
                    }
                    if (isset($registro->perfil_docente_id)) {
                        $candidatos = $candidatos->merge(DB::table('perfil_docente as p')->join('vinculos_laborales as v', 'v.id', '=', 'p.vinculo_laboral_id')
                            ->where('p.id', $registro->perfil_docente_id)->where('v.usuario_id', $registro->usuario_id)->pluck('v.legajo_id'));
                    }
                    $candidatos = $candidatos->filter()->unique();
                    if ($candidatos->isEmpty()) {
                        $candidatos = DB::table('legajos')->where('usuario_id', $registro->usuario_id)->pluck('id');
                    }
                    if ($candidatos->count() === 1) {
                        DB::table($tabla)->where('id', $registro->id)->update(['legajo_id' => $candidatos->first()]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLAS) as $tabla) {
            Schema::table($tabla, fn (Blueprint $columnas) => $columnas->dropConstrainedForeignId('legajo_id'));
        }
    }
};
