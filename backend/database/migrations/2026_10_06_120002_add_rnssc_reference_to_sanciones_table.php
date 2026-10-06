<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sanciones', function (Blueprint $table) {
            $table->date('fecha_registro_rnssc')->nullable();
            $table->text('referencia_rnssc')->nullable();
            $table->text('referencia_procedimiento')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('sanciones')->whereNotNull('fecha_registro_rnssc')->orWhereNotNull('referencia_rnssc')->orWhereNotNull('referencia_procedimiento')->exists()) {
            throw new RuntimeException('Hay referencias de sanciones registradas; las columnas no pueden retirarse.');
        }

        Schema::table('sanciones', function (Blueprint $table) {
            $table->dropColumn(['fecha_registro_rnssc', 'referencia_rnssc', 'referencia_procedimiento']);
        });
    }
};
