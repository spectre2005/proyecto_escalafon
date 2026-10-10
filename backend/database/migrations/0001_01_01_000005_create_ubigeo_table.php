<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ubigeo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('region', 100);
            $table->string('provincia', 100);
            $table->string('distrito', 100);
            $table->text('descripcion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ubigeo');
    }
};
