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
        Schema::table('procesos_fiscalizacion', function (Blueprint $table): void {
            $table->foreignUuid('tipo_documento_id')->nullable()->constrained('tipos_documento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('procesos_fiscalizacion', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tipo_documento_id');
        });
    }
};
