<?php

use App\Models\TipoDocumento;
use Database\Seeders\Seccion08CatalogoSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanciones: fecha de notificación (de ella corren los plazos de recurso y de rehabilitación) y la rehabilitación, que
 * deja de mostrar la sanción en el informe sin borrarla. Catálogo de la sección 08: inicio del procedimiento
 * disciplinario y medida cautelar o preventiva, como documentos de sanciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sanciones', function (Blueprint $table) {
            $table->date('fecha_notificacion')->nullable();
            $table->date('rehabilitada_el')->nullable();
            $table->string('resolucion_rehabilitacion', 60)->nullable();
            $table->uuid('rehabilitada_por')->nullable();
            $table->foreign('rehabilitada_por', 'fk_42_rehabilitada_por')->references('id')->on('usuarios')->restrictOnDelete();
        });
        (new Seccion08CatalogoSeeder)->run();
    }

    public function down(): void
    {
        $nuevos = TipoDocumento::query()->where('seccion_legajo_id', 8)->whereIn('codigo', ['SAN_INICIO_PAD', 'SAN_MEDIDA_CAUTELAR']);
        if ((clone $nuevos)->whereHas('documentosUsuario')->exists()) {
            throw new RuntimeException('Hay documentos de inicio de procedimiento o de medida cautelar. No se puede retirar su catálogo.');
        }
        $nuevos->delete();
        Schema::table('sanciones', function (Blueprint $table) {
            $table->dropForeign('fk_42_rehabilitada_por');
            $table->dropColumn(['fecha_notificacion', 'rehabilitada_el', 'resolucion_rehabilitacion', 'rehabilitada_por']);
        });
    }
};
