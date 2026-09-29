<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->unique('numero_doc_identidad', 'usuarios_documento_unique');
        });
        DB::statement('CREATE UNIQUE INDEX usuarios_email_lower_unique ON usuarios (LOWER(email_institucional))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropUnique('usuarios_documento_unique');
        });
        DB::statement('DROP INDEX usuarios_email_lower_unique');
    }
};
