<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Catálogos del vínculo que la ORH amplía desde el registro de personal y desde Configuración (nada se borra: se
 * desactiva). El vínculo guarda la referencia y, además, el texto del momento (nombre_puesto, proceso_seleccion), para
 * que renombrar un elemento del catálogo no altere el legajo.
 * - tipos_vinculo: los tres tipos de la directiva. Solo se edita su descripción: de ellos depende qué documentos se exigen.
 * - modalidades_vinculo: segundo nivel del tipo de vínculo (p. ej. CAS dentro de plazo determinado).
 * - puestos: denominaciones de puesto por unidad orgánica, con valores por defecto de sus condiciones.
 * - tipos_proceso_seleccion: proceso CAS, concurso público, concurso docente… (carrera y plazo determinado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_vinculo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 60);
            $table->string('descripcion', 300)->nullable();
            $table->unsignedTinyInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('modalidades_vinculo', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 120);
            $table->string('regimen_laboral', 20);
            $table->string('tipo_personal', 15)->nullable();
            $table->foreignUuid('regimen_normativo_id')->nullable()->constrained('regimenes_normativos')->restrictOnDelete();
            $table->boolean('tiene_periodo_prueba')->nullable();
            $table->boolean('exige_fin_previsto')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('puestos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 150);
            $table->string('tipo_personal', 15);
            $table->foreignUuid('unidad_organica_id')->nullable()->constrained('unidades_organicas')->restrictOnDelete();
            $table->string('grupo_ocupacional', 20)->nullable();
            $table->boolean('es_cargo_confianza')->default(false);
            $table->boolean('obligado_djbr')->nullable();
            $table->boolean('exige_colegiatura')->nullable();
            $table->boolean('tiene_periodo_prueba')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('tipos_proceso_seleccion', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 120);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('vinculos_laborales', function (Blueprint $table) {
            $table->foreignUuid('modalidad_vinculo_id')->nullable()->constrained('modalidades_vinculo')->restrictOnDelete();
            $table->foreignUuid('puesto_id')->nullable()->constrained('puestos')->restrictOnDelete();
            $table->foreignUuid('tipo_proceso_seleccion_id')->nullable()->constrained('tipos_proceso_seleccion')->restrictOnDelete();
            $table->date('documento_vinculo_fecha')->nullable();
        });

        $ahora = now();
        foreach ([
            ['carrera', 'Carrera', 'Personal con permanencia: nombrados y docentes ordinarios.'],
            ['plazo_determinado', 'Plazo determinado', 'Contratos con plazo: CAS, plazo fijo y docentes contratados.'],
            ['designado', 'Designado (confianza)', 'Cargos de confianza y designaciones, que terminan cuando se dan por concluidas.'],
        ] as $orden => [$codigo, $nombre, $descripcion]) {
            DB::table('tipos_vinculo')->insert(['id' => (string) Str::uuid(), 'codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => $descripcion,
                'orden' => $orden + 1, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
        foreach ([['PS-001', 'Proceso CAS'], ['PS-002', 'Concurso público de méritos'], ['PS-003', 'Concurso docente']] as [$codigo, $nombre]) {
            DB::table('tipos_proceso_seleccion')->insert(['id' => (string) Str::uuid(), 'codigo' => $codigo, 'nombre' => $nombre, 'activo' => true,
                'created_at' => $ahora, 'updated_at' => $ahora]);
        }
    }

    public function down(): void
    {
        Schema::table('vinculos_laborales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tipo_proceso_seleccion_id');
            $table->dropConstrainedForeignId('puesto_id');
            $table->dropConstrainedForeignId('modalidad_vinculo_id');
            $table->dropColumn('documento_vinculo_fecha');
        });
        Schema::dropIfExists('tipos_proceso_seleccion');
        Schema::dropIfExists('puestos');
        Schema::dropIfExists('modalidades_vinculo');
        Schema::dropIfExists('tipos_vinculo');
    }
};
