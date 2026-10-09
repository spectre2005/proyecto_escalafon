<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secciones_legajo', function (Blueprint $table): void {
            $table->boolean('activa')->default(true);
            $table->string('disponibilidad', 25)->default('siempre_activa');
        });
        DB::table('secciones_legajo')->whereIn('id', [9, 11, 12])->update(['disponibilidad' => 'periodo_pendiente']);
        foreach (['personal' => 'Personal (consulta de su propio legajo)', 'asistente_legajo' => 'Asistente de legajo'] as $codigo => $nombre) {
            if (! DB::table('roles')->where('codigo', $codigo)->exists()) {
                DB::table('roles')->insert(['id' => (string) Str::uuid(), 'codigo' => $codigo, 'nombre' => $nombre, 've_datos_sensibles' => false, 'activo' => true]);
            }
        }
        Schema::create('incorporaciones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('usuario_id')->unique()->constrained('usuarios')->restrictOnDelete();
            $table->string('estado', 25)->default('registrado')->index();
            $table->string('cargo', 200);
            $table->string('numero_resolucion', 100)->nullable();
            $table->date('fecha_resolucion')->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->foreignUuid('responsable_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('registrado_por')->constrained('usuarios')->restrictOnDelete();
            $table->foreignUuid('vinculo_laboral_id')->nullable()->constrained('vinculos_laborales')->restrictOnDelete();
            $table->json('documentos');
            $table->json('tareas');
            $table->json('historial');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('firma_solicitada_at')->nullable();
            $table->timestamp('incorporado_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incorporaciones');
        Schema::table('secciones_legajo', fn (Blueprint $table) => $table->dropColumn(['activa', 'disponibilidad']));
    }
};
