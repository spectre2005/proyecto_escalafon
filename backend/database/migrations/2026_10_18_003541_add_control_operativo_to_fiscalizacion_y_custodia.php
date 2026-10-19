<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['procesos_fiscalizacion', 'solicitudes_acceso_legajo', 'movimientos_custodia_legajo'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->unsignedInteger('version')->default(1);
            });
        }
        Schema::table('procesos_fiscalizacion', function (Blueprint $table): void {
            $table->string('resultado', 15)->nullable();
        });
        Schema::table('solicitudes_acceso_legajo', function (Blueprint $table): void {
            $table->json('documentos_autorizados')->nullable();
        });
        Schema::table('movimientos_custodia_legajo', function (Blueprint $table): void {
            $table->foreignUuid('solicitud_acceso_id')->nullable()->constrained('solicitudes_acceso_legajo');
            $table->date('fecha_devolucion_prevista')->nullable();
            $table->timestamp('devuelto_en')->nullable();
            $table->foreignUuid('recibido_por')->nullable()->constrained('usuarios');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_custodia_legajo', function (Blueprint $table): void {
            $table->dropForeign(['solicitud_acceso_id']);
            $table->dropForeign(['recibido_por']);
            $table->dropColumn(['solicitud_acceso_id', 'fecha_devolucion_prevista', 'devuelto_en', 'recibido_por']);
        });
        Schema::table('solicitudes_acceso_legajo', fn (Blueprint $table) => $table->dropColumn('documentos_autorizados'));
        Schema::table('procesos_fiscalizacion', fn (Blueprint $table) => $table->dropColumn('resultado'));
        foreach (['procesos_fiscalizacion', 'solicitudes_acceso_legajo', 'movimientos_custodia_legajo'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $table) => $table->dropColumn('version'));
        }
    }
};
