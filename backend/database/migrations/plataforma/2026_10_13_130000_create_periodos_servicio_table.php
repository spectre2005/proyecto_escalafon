<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiempo de uso pagado por cada institución.
 * - periodos_servicio: cada pago cubre de «desde» a «hasta» (ambos incluidos, fechas en PLATAFORMA_ZONA_HORARIA), con
 *   monto y comprobante opcionales. No se editan ni se borran: un error se anula con motivo y se registra otro.
 * - instituciones.dias_gracia: días después del vencimiento en que sigue funcionando (con aviso) antes del bloqueo.
 */
return new class extends Migration
{
    protected $connection = 'plataforma';

    public function up(): void
    {
        Schema::connection('plataforma')->table('instituciones', function (Blueprint $table): void {
            $table->unsignedSmallInteger('dias_gracia')->default(0);
        });

        Schema::connection('plataforma')->create('periodos_servicio', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('institucion_id');
            $table->date('desde');
            $table->date('hasta');
            $table->decimal('monto', 12, 2)->nullable();
            $table->string('comprobante', 100)->nullable();
            $table->string('observacion', 500)->nullable();
            $table->uuid('registrado_por')->nullable();
            $table->boolean('anulado')->default(false);
            $table->string('motivo_anulacion', 500)->nullable();
            $table->uuid('anulado_por')->nullable();
            $table->timestamp('anulado_en')->nullable();
            $table->timestamps();
            $table->foreign('institucion_id')->references('id')->on('instituciones');
            $table->foreign('registrado_por')->references('id')->on('administradores_plataforma');
            $table->foreign('anulado_por')->references('id')->on('administradores_plataforma');
            $table->index(['institucion_id', 'anulado', 'desde']);
        });
    }

    public function down(): void
    {
        Schema::connection('plataforma')->dropIfExists('periodos_servicio');
        Schema::connection('plataforma')->table('instituciones', function (Blueprint $table): void {
            $table->dropColumn('dias_gracia');
        });
    }
};
