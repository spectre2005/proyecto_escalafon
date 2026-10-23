<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procesos_fiscalizacion', function (Blueprint $table): void {
            $table->string('codigo', 40)->nullable()->unique();
            $table->text('motivo')->nullable();
            $table->text('fundamento')->nullable();
            $table->foreignUuid('revisor_id')->nullable()->constrained('usuarios');
            $table->text('revision_observaciones')->nullable();
            $table->timestamp('presentado_en')->nullable();
            $table->timestamp('revisado_en')->nullable();
        });
        Schema::table('documentos_usuario', function (Blueprint $table): void {
            $table->string('resultado_fiscalizacion', 25)->nullable();
            $table->foreignUuid('proceso_fiscalizacion_id')->nullable()->constrained('procesos_fiscalizacion');
        });
        Schema::create('fiscalizacion_documentos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('proceso_id')->constrained('procesos_fiscalizacion');
            $table->foreignUuid('documento_id')->constrained('documentos_usuario');
            $table->foreignUuid('archivo_id')->nullable()->constrained('archivos_documento');
            $table->string('hash_sha256', 64)->nullable();
            $table->json('datos_snapshot');
            $table->string('resultado', 25)->default('pendiente');
            $table->text('conclusion')->nullable();
            $table->foreignUuid('evidencia_documento_id')->nullable()->constrained('documentos_usuario');
            $table->foreignUuid('evaluado_por')->nullable()->constrained('usuarios');
            $table->timestamp('evaluado_en')->nullable();
            $table->timestamps();
            $table->unique(['proceso_id', 'documento_id']);
        });
        Schema::create('fiscalizacion_verificaciones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('alcance_id')->constrained('fiscalizacion_documentos');
            $table->string('fuente', 40);
            $table->string('estado_tecnico', 25);
            $table->string('resultado_fuente', 40);
            $table->json('detalle');
            $table->foreignUuid('registrado_por')->constrained('usuarios');
            $table->timestamp('created_at');
            $table->index(['alcance_id', 'created_at']);
        });
        Schema::create('fiscalizacion_derivaciones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('proceso_id')->constrained('procesos_fiscalizacion');
            $table->string('area', 150);
            $table->text('motivo');
            $table->string('referencia', 150);
            $table->foreignUuid('documento_id')->constrained('documentos_usuario');
            $table->date('fecha_envio');
            $table->date('fecha_cargo')->nullable();
            $table->foreignUuid('cargo_documento_id')->nullable()->constrained('documentos_usuario');
            $table->foreignUuid('registrado_por')->constrained('usuarios');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscalizacion_derivaciones');
        Schema::dropIfExists('fiscalizacion_verificaciones');
        Schema::dropIfExists('fiscalizacion_documentos');
        Schema::table('documentos_usuario', function (Blueprint $table): void {
            $table->dropForeign(['proceso_fiscalizacion_id']);
            $table->dropColumn(['resultado_fiscalizacion', 'proceso_fiscalizacion_id']);
        });
        Schema::table('procesos_fiscalizacion', function (Blueprint $table): void {
            $table->dropForeign(['revisor_id']);
            $table->dropUnique(['codigo']);
            $table->dropColumn(['codigo', 'motivo', 'fundamento', 'revisor_id', 'revision_observaciones', 'presentado_en', 'revisado_en']);
        });
    }
};
