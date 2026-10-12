<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base de la plataforma SaaS (conexión «plataforma», separada de las bases institucionales). Se aplica aparte:
 * php artisan migrate --database=plataforma --path=database/migrations/plataforma
 *
 * - administradores_plataforma: cuentas del panel de superadministrador (no son usuarios institucionales).
 * - instituciones: cada entidad cliente, con su base de datos y su almacenamiento propios. Estados: registrada (aún sin
 *   aprovisionar), activa y suspendida. Solo se activa con aprovisionada_en (base, archivos y configuración listos).
 * - auditoria_plataforma: operaciones del panel (no se editan ni se borran).
 */
return new class extends Migration
{
    protected $connection = 'plataforma';

    public function up(): void
    {
        Schema::connection('plataforma')->create('administradores_plataforma', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nombre', 150);
            $table->string('email', 255);
            $table->string('password');
            $table->boolean('activo')->default(true);
            $table->timestamp('ultimo_acceso')->nullable();
            $table->timestamps();
            $table->unique('email');
        });

        Schema::connection('plataforma')->create('instituciones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nombre', 150);
            $table->string('slug', 40);
            $table->string('estado', 20)->default('registrada');
            $table->string('base_datos', 63);
            $table->string('almacenamiento', 120);
            $table->timestamp('aprovisionada_en')->nullable();
            $table->timestamp('suspendida_en')->nullable();
            $table->string('motivo_suspension', 500)->nullable();
            $table->timestamps();
            $table->unique('slug');
            $table->unique('base_datos');
            $table->unique('almacenamiento');
            $table->index('estado');
        });

        Schema::connection('plataforma')->create('auditoria_plataforma', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('administrador_id')->nullable();
            $table->string('accion', 60);
            $table->uuid('institucion_id')->nullable();
            $table->text('datos_antes')->nullable();
            $table->text('datos_despues')->nullable();
            $table->string('motivo', 500)->nullable();
            $table->string('ip_origen', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->timestamp('created_at');
            $table->foreign('administrador_id')->references('id')->on('administradores_plataforma');
            $table->foreign('institucion_id')->references('id')->on('instituciones');
            $table->index(['institucion_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('plataforma')->dropIfExists('auditoria_plataforma');
        Schema::connection('plataforma')->dropIfExists('instituciones');
        Schema::connection('plataforma')->dropIfExists('administradores_plataforma');
    }
};
