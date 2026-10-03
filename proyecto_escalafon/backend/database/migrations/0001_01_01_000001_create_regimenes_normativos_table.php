<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regimenes_normativos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('codigo', 20);
            $table->string('nombre', 150);
            $table->string('aplica_a', 15);
            $table->boolean('activo')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regimenes_normativos');
    }
};
