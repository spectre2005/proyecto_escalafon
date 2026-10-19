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
        Schema::table('requerimientos_documentos', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->integer('plazo_dias_habiles')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('requerimientos_documentos', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
