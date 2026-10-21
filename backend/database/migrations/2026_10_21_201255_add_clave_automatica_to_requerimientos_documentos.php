<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requerimientos_documentos', function (Blueprint $table): void {
            $table->string('clave_automatica', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('requerimientos_documentos', function (Blueprint $table): void {
            $table->dropUnique(['clave_automatica']);
            $table->dropColumn('clave_automatica');
        });
    }
};
