<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reglas_vigencia_categoria', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('categoria', 10)->unique();
            $table->unsignedSmallInteger('anios');
            $table->unsignedSmallInteger('version')->default(1);
        });
        foreach (['auxiliar' => 3, 'asociado' => 5, 'principal' => 7] as $categoria => $anios) {
            DB::table('reglas_vigencia_categoria')->insert(['id' => (string) Str::uuid(), 'categoria' => $categoria, 'anios' => $anios, 'version' => 1]);
        }
        Schema::create('politicas_renovacion', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('categoria', 10);
            $table->foreignUuid('tipo_documento_id')->constrained('tipos_documento');
            $table->string('modo', 20);
            $table->unsignedInteger('antiguedad_maxima_dias')->nullable();
            $table->unsignedInteger('version');
            $table->boolean('actual')->default(true);
            $table->foreignUuid('configurada_por')->constrained('usuarios');
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX politica_renovacion_actual ON politicas_renovacion (categoria, tipo_documento_id) WHERE actual = true');
        Schema::create('ciclos_vigencia_legajo', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('legajo_id')->constrained('legajos');
            $table->foreignUuid('perfil_docente_id')->constrained('perfil_docente');
            $table->foreignUuid('regla_id')->constrained('reglas_vigencia_categoria');
            $table->string('categoria', 10);
            $table->unsignedSmallInteger('anios');
            $table->unsignedSmallInteger('version_regla');
            $table->date('fecha_base');
            $table->date('fecha_vencimiento')->index();
            $table->date('fecha_aviso')->index();
            $table->string('origen_base', 30);
            $table->foreignUuid('documento_base_id')->nullable()->constrained('documentos_usuario');
            $table->foreignUuid('jefe_inmediato_id')->nullable()->constrained('usuarios');
            $table->foreignUuid('creado_por')->nullable()->constrained('usuarios');
            $table->boolean('actual')->default(true);
            $table->string('estado_vigencia', 20)->default('vigente');
            $table->timestamp('cerrado_en')->nullable();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX ciclo_vigencia_actual ON ciclos_vigencia_legajo (legajo_id) WHERE actual = true');
        Schema::table('requerimientos_documentos', function (Blueprint $table): void {
            $table->integer('plazo_dias_habiles')->nullable()->change();
            $table->uuid('solicitado_por')->nullable()->change();
            $table->string('unidad_plazo', 15)->default('habiles');
            $table->foreignUuid('ciclo_id')->nullable()->constrained('ciclos_vigencia_legajo');
            $table->foreignUuid('politica_id')->nullable()->constrained('politicas_renovacion');
            $table->foreignUuid('documento_objetivo_id')->nullable()->constrained('documentos_usuario');
            $table->string('motivo_renovacion', 100)->nullable();
            $table->unsignedInteger('antiguedad_maxima_dias')->nullable();
            $table->date('fecha_vencimiento_evidencia')->nullable();
            $table->unique(['ciclo_id', 'tipo_documento_id'], 'requisito_ciclo_tipo_unico');
        });
        Schema::create('renovaciones_legajo', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ciclo_id')->unique()->constrained('ciclos_vigencia_legajo');
            $table->string('estado', 20)->default('borrador');
            $table->text('observacion')->nullable();
            $table->timestamp('presentada_en')->nullable();
            $table->timestamp('aprobada_en')->nullable();
            $table->foreignUuid('aprobada_por')->nullable()->constrained('usuarios');
            $table->foreignUuid('ciclo_nuevo_id')->nullable()->constrained('ciclos_vigencia_legajo');
            $table->timestamps();
        });
        Schema::create('presentaciones_renovacion', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('requerimiento_id')->constrained('requerimientos_documentos');
            $table->string('storage_path');
            $table->string('nombre_original');
            $table->string('hash_sha256', 64);
            $table->unsignedBigInteger('tamano_bytes');
            $table->date('fecha_vencimiento')->nullable();
            $table->foreignUuid('subido_por')->constrained('usuarios');
            $table->boolean('actual')->default(true);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX presentacion_requisito_actual ON presentaciones_renovacion (requerimiento_id) WHERE actual = true');
        Schema::create('avisos_vencimiento', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ciclo_id')->constrained('ciclos_vigencia_legajo');
            $table->string('clave')->unique();
            $table->string('origen', 20);
            $table->date('fecha_programada');
            $table->foreignUuid('solicitado_por')->nullable()->constrained('usuarios');
            $table->timestamps();
        });
        Schema::create('envios_vencimiento', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('aviso_id')->constrained('avisos_vencimiento');
            $table->foreignUuid('destinatario_id')->constrained('usuarios');
            $table->string('canal', 10)->default('correo');
            $table->string('destino')->nullable();
            $table->string('estado', 20)->default('pendiente');
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->timestamp('proximo_intento_en')->nullable();
            $table->timestamp('enviado_en')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamps();
            $table->unique(['aviso_id', 'destinatario_id', 'canal'], 'envio_aviso_destinatario_unico');
        });
        Schema::table('notificaciones_usuario', function (Blueprint $table): void {
            $table->foreignUuid('aviso_vencimiento_id')->nullable()->constrained('avisos_vencimiento');
            $table->unique(['aviso_vencimiento_id', 'usuario_id'], 'notificacion_aviso_usuario_unico');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notificaciones_usuario', function (Blueprint $table): void {
            $table->dropUnique('notificacion_aviso_usuario_unico');
            $table->dropConstrainedForeignId('aviso_vencimiento_id');
        });
        Schema::dropIfExists('envios_vencimiento');
        Schema::dropIfExists('avisos_vencimiento');
        Schema::dropIfExists('presentaciones_renovacion');
        Schema::dropIfExists('renovaciones_legajo');
        Schema::table('requerimientos_documentos', function (Blueprint $table): void {
            $table->dropUnique('requisito_ciclo_tipo_unico');
            foreach (['ciclo_id', 'politica_id', 'documento_objetivo_id'] as $campo) {
                $table->dropConstrainedForeignId($campo);
            }
            $table->dropColumn(['unidad_plazo', 'motivo_renovacion', 'antiguedad_maxima_dias', 'fecha_vencimiento_evidencia']);
        });
        Schema::dropIfExists('ciclos_vigencia_legajo');
        Schema::dropIfExists('politicas_renovacion');
        Schema::dropIfExists('reglas_vigencia_categoria');
    }
};
