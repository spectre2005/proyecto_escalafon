<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requerimientos_documentos', function (Blueprint $table): void {
            $table->string('clase', 20)->default('documentos');
            $table->json('seguimiento')->nullable();
            $table->timestamp('recordado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('requerimientos_documentos', function (Blueprint $table): void {
            $table->dropColumn(['clase', 'seguimiento', 'recordado_en']);
        });
    }
};
