<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificaciones_usuario', function (Blueprint $table): void {
            $table->string('correo_id_proveedor', 255)->nullable();
        });
        Schema::create('conexiones_correo', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('clave')->unique();
            $table->string('email', 255);
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expira_en')->nullable();
            $table->boolean('habilitada')->default(true);
            $table->foreignUuid('conectado_por')->constrained('usuarios');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conexiones_correo');
        Schema::table('notificaciones_usuario', fn (Blueprint $table) => $table->dropColumn('correo_id_proveedor'));
    }
};
