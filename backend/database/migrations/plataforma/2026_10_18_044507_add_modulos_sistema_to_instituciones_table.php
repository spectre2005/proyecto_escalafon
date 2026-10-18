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
        Schema::connection('plataforma')->table('instituciones', function (Blueprint $table): void {
            $table->json('modulos_sistema')->nullable();
        });
        // Estado inicial del cliente existente; después se modifica con el mismo interruptor que cualquier entidad.
        DB::connection('plataforma')->table('instituciones')->where('slug', 'uniq')->update(['modulos_sistema' => json_encode([
            'evaluaciones' => ['activa' => false, 'motivo' => 'Configuración inicial de UNIQ: no utiliza la consulta general de evaluaciones.',
                'fecha' => now()->toIso8601String(), 'por' => 'Configuración inicial', 'origen' => 'inicial'],
        ], JSON_THROW_ON_ERROR)]);
    }

    public function down(): void
    {
        Schema::connection('plataforma')->table('instituciones', function (Blueprint $table): void {
            $table->dropColumn('modulos_sistema');
        });
    }
};
