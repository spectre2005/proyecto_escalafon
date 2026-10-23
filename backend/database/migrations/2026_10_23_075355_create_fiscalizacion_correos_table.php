<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscalizacion_correos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('proceso_id')->constrained('procesos_fiscalizacion');
            $table->foreignUuid('documento_id')->constrained('documentos_usuario');
            $table->foreignUuid('registrado_por')->constrained('usuarios');
            $table->foreignUuid('solicitud_id')->nullable()->constrained('solicitudes_fiscalizacion');
            $table->string('hash_contenido', 64);
            $table->string('estado', 25);
            $table->string('remitente', 254);
            $table->string('destinatario', 254);
            $table->string('asunto', 250);
            $table->text('cuerpo');
            $table->string('mensaje_id', 255)->nullable();
            $table->string('proveedor', 20);
            $table->text('error')->nullable();
            $table->timestamp('enviado_en')->nullable();
            $table->timestamps();
            $table->index(['proceso_id', 'hash_contenido']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscalizacion_correos');
    }
};
