<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vinculos_laborales', function (Blueprint $table): void {
            $table->foreignUuid('legajo_id')->nullable()->constrained('legajos')->restrictOnDelete();
        });
        DB::table('vinculos_laborales')->whereNull('legajo_id')
            ->whereRaw('(SELECT COUNT(*) FROM legajos WHERE legajos.usuario_id = vinculos_laborales.usuario_id) = 1')
            ->update(['legajo_id' => DB::raw('(SELECT id FROM legajos WHERE legajos.usuario_id = vinculos_laborales.usuario_id LIMIT 1)')]);
        DB::table('vinculos_laborales')->whereNull('legajo_id')
            ->whereRaw('(SELECT COUNT(DISTINCT d.legajo_id) FROM documentos_usuario d WHERE d.vinculo_laboral_id = vinculos_laborales.id AND d.usuario_id = vinculos_laborales.usuario_id) = 1')
            ->update(['legajo_id' => DB::raw('(SELECT d.legajo_id FROM documentos_usuario d WHERE d.vinculo_laboral_id = vinculos_laborales.id AND d.usuario_id = vinculos_laborales.usuario_id LIMIT 1)')]);
    }

    public function down(): void
    {
        Schema::table('vinculos_laborales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legajo_id');
        });
    }
};
