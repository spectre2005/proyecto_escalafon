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
        Schema::table('usuario_roles', function (Blueprint $table) {
            $table->string('motivo', 500)->nullable();
            $table->timestamp('revocado_en')->nullable();
            $table->foreignUuid('revocado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('usuario_roles', function (Blueprint $table) {
            $table->dropForeign(['revocado_por']);
            $table->dropColumn(['motivo', 'revocado_en', 'revocado_por']);
        });
    }
};
