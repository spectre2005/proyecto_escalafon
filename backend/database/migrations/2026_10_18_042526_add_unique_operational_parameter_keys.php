<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX parametros_operativos_clave_unica ON parametros_sistema (clave) WHERE clave IN ('dias_aviso_vencimiento_vinculo', 'tamano_maximo_pdf_kb', 'numero_legajo_prefijo', 'numero_legajo_digitos', 'configuracion_parametros_version')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX parametros_operativos_clave_unica');
    }
};
