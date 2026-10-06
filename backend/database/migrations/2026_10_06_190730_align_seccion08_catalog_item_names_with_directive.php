<?php

use Database\Seeders\Seccion08CatalogoSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        (new Seccion08CatalogoSeeder)->run();
    }

    public function down(): void
    {
        $anteriores = [
            'REC_CARTA_FELICITACION' => ['Cartas, oficios, memorandos o diplomas de reconocimiento', 'Documentos de reconocimiento por mérito o participación excepcional.'],
            'REC_LABOR_DOCENTE' => ['Reconocimiento excepcional por labor docente', 'Solo aplica al personal docente.'],
            'REC_TIEMPO_SERVICIOS' => ['Reconocimiento por tiempo de servicios', 'Dato documental opcional; el tiempo reconocido no sustituye el cálculo de experiencia.'],
            'SAN_RESOLUCION_SANCION' => ['Resolución que formaliza una sanción disciplinaria', 'Amonestación escrita, suspensión o destitución.'],
            'SAN_RESOLUCION_RECURSO' => ['Resolución sobre recurso impugnatorio', 'Documento vinculado a una sanción disciplinaria.'],
        ];

        foreach ($anteriores as $codigo => [$nombre, $descripcion]) {
            DB::table('tipos_documento')->where('seccion_legajo_id', 8)->where('codigo', $codigo)
                ->update(['nombre' => $nombre, 'descripcion' => $descripcion]);
        }
    }
};
