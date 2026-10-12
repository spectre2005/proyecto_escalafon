<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Banderas del perfil con tres estados: true = Sí, false = No, null = Sin responder.
     * - Puesto (en el vínculo; la copia de usuarios queda como respaldo de lectura sin vínculo vigente):
     *   obligado_djbr, exige_colegiatura, exige_estudios_basicos, tiene_periodo_prueba.
     * - Persona (solo en usuarios): tiene_derechohabientes, con_discapacidad.
     *
     * Hasta ahora toda bandera nacía en false, así que un false no distingue «No» de «nadie preguntó»: los false
     * pasan a Sin responder y los true se conservan.
     */
    private const BANDERAS = [
        'usuarios' => ['obligado_djbr', 'exige_colegiatura', 'exige_estudios_basicos', 'tiene_periodo_prueba', 'tiene_derechohabientes', 'con_discapacidad'],
        'vinculos_laborales' => ['obligado_djbr', 'exige_colegiatura', 'exige_estudios_basicos', 'tiene_periodo_prueba'],
    ];

    public function up(): void
    {
        foreach (self::BANDERAS as $tabla => $columnas) {
            $this->cambiarColumnas($tabla, function (Blueprint $table) use ($columnas): void {
                foreach ($columnas as $columna) {
                    $table->boolean($columna)->nullable()->default(null)->change();
                }
            });
            foreach ($columnas as $columna) {
                DB::table($tabla)->where($columna, false)->update([$columna => null]);
            }
        }
    }

    /** Sin responder vuelve a false: es la semántica anterior, que no tenía ese estado. */
    public function down(): void
    {
        foreach (self::BANDERAS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                DB::table($tabla)->whereNull($columna)->update([$columna => false]);
            }
            $this->cambiarColumnas($tabla, function (Blueprint $table) use ($columnas): void {
                foreach ($columnas as $columna) {
                    $table->boolean($columna)->nullable(false)->default(false)->change();
                }
            });
        }
    }

    /**
     * En SQLite (tests) change() reconstruye la tabla y no sabe copiar el índice por expresión de usuarios
     * (LOWER(email_institucional), 2026_09_29_200614): se quita antes y se vuelve a crear igual. PostgreSQL altera la
     * columna sin tocar los índices.
     */
    private function cambiarColumnas(string $tabla, Closure $cambios): void
    {
        $indice = $tabla === 'usuarios' && DB::getDriverName() === 'sqlite';
        if ($indice) {
            DB::statement('DROP INDEX usuarios_email_lower_unique');
        }
        Schema::table($tabla, $cambios);
        if ($indice) {
            DB::statement('CREATE UNIQUE INDEX usuarios_email_lower_unique ON usuarios (LOWER(email_institucional))');
        }
    }
};
