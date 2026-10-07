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
        Schema::table('documentos_usuario', function (Blueprint $table) {
            $table->string('relacion_titular_otro', 150)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documentos_usuario', function (Blueprint $table) {
            $table->dropColumn('relacion_titular_otro');
        });
    }
};
