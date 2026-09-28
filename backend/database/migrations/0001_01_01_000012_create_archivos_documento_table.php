<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archivos_documento', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('almacenamiento', 30)->default('local');
            $table->string('bucket', 100)->nullable();
            $table->string('storage_path', 500);
            $table->string('nombre_original', 255);
            $table->string('mime_type', 100)->default('application/pdf');
            $table->bigInteger('tamano_bytes');
            $table->char('hash_sha256', 64);
            $table->integer('num_paginas')->nullable();
            $table->boolean('firmado_digitalmente')->default(false);
            $table->boolean('cifrado')->default(true);
            $table->string('estado_antivirus', 15)->default('pendiente');
            $table->uuid('subido_por');
            $table->timestamp('subido_en')->nullable();

            $table->foreign('subido_por', 'fk_12_subido_por')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archivos_documento');
    }
};
