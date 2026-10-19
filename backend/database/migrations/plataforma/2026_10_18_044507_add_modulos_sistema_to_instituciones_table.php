<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'plataforma';

    public function up(): void
    {
        Schema::connection('plataforma')->table('instituciones', function (Blueprint $table): void {
            $table->json('modulos_sistema')->nullable();
        });
        // Sin valor guardado todos los módulos quedan activos; cada institución los ajusta desde su configuración.
    }

    public function down(): void
    {
        Schema::connection('plataforma')->table('instituciones', function (Blueprint $table): void {
            $table->dropColumn('modulos_sistema');
        });
    }
};
