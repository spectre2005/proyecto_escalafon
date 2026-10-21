<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('plataforma')->table('identidades_dni', function (Blueprint $table): void {
            $table->json('ubicacion')->nullable();
            $table->timestamp('ubicacion_consultada_en')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('plataforma')->table('identidades_dni', function (Blueprint $table): void {
            $table->dropColumn(['ubicacion', 'ubicacion_consultada_en']);
        });
    }
};
