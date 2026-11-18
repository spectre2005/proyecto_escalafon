<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listas de puestos y unidades orgánicas propias de cada institución (observación del docente, 17/11/2026). Empiezan
 * vacías y se llenan con lo que se registra en los vínculos (App\Support\CatalogosVinculo); el administrador las corrige.
 *
 * - puestos: nueva. nombre_normalizado (sin mayúsculas, tildes ni espacios repetidos) evita duplicados por tipo de personal.
 * - unidades_organicas: código y tipo pasan a opcionales (una unidad creada desde un vínculo solo trae el nombre) y se
 *   guarda quién la registró. Aditiva: no cambia ni borra filas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puestos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nombre', 150);
            $table->string('nombre_normalizado', 150);
            $table->string('tipo_personal', 20);
            $table->boolean('activo')->default(true);
            $table->uuid('registrado_por')->nullable();
            $table->timestamps();
            $table->unique(['tipo_personal', 'nombre_normalizado'], 'uq_puestos_tipo_nombre');
            $table->foreign('registrado_por', 'fk_puestos_registrado_por')->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('no action');
        });

        Schema::table('unidades_organicas', function (Blueprint $table) {
            $table->string('codigo', 20)->nullable()->change();
            $table->string('tipo', 30)->nullable()->change();
            $table->uuid('registrado_por')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('unidades_organicas', function (Blueprint $table) {
            $table->dropColumn('registrado_por');
        });
        Schema::dropIfExists('puestos');
    }
};
