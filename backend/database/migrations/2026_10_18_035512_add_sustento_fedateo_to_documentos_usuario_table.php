<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos_usuario', function (Blueprint $table): void {
            $table->foreignUuid('fedateo_sustento_documento_id')->nullable()->constrained('documentos_usuario');
        });
    }

    public function down(): void
    {
        Schema::table('documentos_usuario', function (Blueprint $table): void {
            $table->dropForeign(['fedateo_sustento_documento_id']);
            $table->dropColumn('fedateo_sustento_documento_id');
        });
    }
};
