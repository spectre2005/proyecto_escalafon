<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('plataforma')->table('instituciones', function (Blueprint $table): void {
            $table->timestamp('archivada_en')->nullable();
            $table->string('motivo_archivo', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('plataforma')->table('instituciones', fn (Blueprint $table) => $table->dropColumn(['archivada_en', 'motivo_archivo']));
    }
};
