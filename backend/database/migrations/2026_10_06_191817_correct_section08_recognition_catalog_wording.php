<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tipos_documento')->where('seccion_legajo_id', 8)->where('codigo', 'REC_CARTA_FELICITACION')
            ->update(['nombre' => 'Cartas, oficios, memorando de felicitación, condecoraciones, o diplomas otorgados en reconocimiento por excepcional participación en proyectos o eventos.']);
    }

    public function down(): void
    {
        DB::table('tipos_documento')->where('seccion_legajo_id', 8)->where('codigo', 'REC_CARTA_FELICITACION')
            ->update(['nombre' => 'Cartas, oficios, memorando de felicitación, condecoraciones, o diplomas otorgados en reconocimiento o por excepcional participación en proyectos o eventos.']);
    }
};
