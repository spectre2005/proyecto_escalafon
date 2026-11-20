<?php

use Database\Seeders\ModalidadVinculoSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modalidades de vínculo de partida en las bases que ya existían: desde el 18/11/2026 el registro de personal solo elige
 * de la lista (no crea modalidades), y una base migrada sin el seeder quedaba con la lista vacía. Solo si la tabla está
 * vacía: lo que la institución ya registró no se toca. El seeder es idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modalidades_vinculo') || DB::table('modalidades_vinculo')->exists()) {
            return;
        }
        (new ModalidadVinculoSeeder)->run();
    }

    /** Nada se borra: las modalidades pueden estar ya en uso. */
    public function down(): void {}
};
