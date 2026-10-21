<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'plataforma';

    public function up(): void
    {
        Schema::connection('plataforma')->table('administradores_plataforma', function (Blueprint $table): void {
            $table->timestampTz('password_temporal_vence_en')->nullable();
        });
        DB::connection('plataforma')->table('administradores_plataforma')->where('debe_cambiar_password', true)->update(['password_temporal_vence_en' => now()->addHours(48)]);
    }

    public function down(): void
    {
        Schema::connection('plataforma')->table('administradores_plataforma', function (Blueprint $table): void {
            $table->dropColumn('password_temporal_vence_en');
        });
    }
};
