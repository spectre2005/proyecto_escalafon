<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Régimen: el vínculo es la fuente del tipo de vínculo y la ley va en regimen_normativo_id.
     *
     * - vinculos_laborales.regimen_laboral admite NULL = «Sin definir». Los valores que no son carrera, plazo_determinado
     *   ni designado (p. ej. una ley como «276») pasan a NULL: la ley no determina el tipo de vínculo, así que no se
     *   convierten; los confirma el administrador al corregir el vínculo. Los vínculos nuevos exigen uno de los tres
     *   (Seccion02Controller) y en PostgreSQL una restricción CHECK lo garantiza.
     * - usuarios.regimen_normativo_id vacío se completa desde el código de ley de usuarios.regimen_laboral (CAS = 1057)
     *   solo si la ley corresponde a su tipo de personal y no choca con la ley de ninguno de sus vínculos no anulados.
     *   Lo demás queda como sugerencia. usuarios.regimen_laboral se conserva como respaldo de lectura de la ley.
     *
     * - Se asegura el mínimo de leyes del catálogo (LEYES), creando solo las que faltan.
     *
     * Reversible: cada valor cambiado o fila creada queda en respaldo_regimen y el down lo restaura.
     */
    private const TIPOS = ['carrera', 'plazo_determinado', 'designado'];

    private const ALIAS_LEY = ['CAS' => '1057'];

    /**
     * Leyes del catálogo (nacionales, no de una entidad). RegimenNormativoSeeder solo corre en local y testing: sin este
     * mínimo, una instalación nueva no tendría leyes y el alta (que pide la ley) no funcionaría. Solo se crean las que faltan.
     */
    private const LEYES = [
        ['30220', 'Ley N° 30220 - Ley Universitaria', 'docente'],
        ['1057', 'Decreto Legislativo N° 1057 (CAS)', 'administrativo'],
        ['276', 'Decreto Legislativo N° 276', 'administrativo'],
        ['728', 'Decreto Legislativo N° 728', 'administrativo'],
        ['30057', 'Ley N° 30057 - Ley del Servicio Civil', 'administrativo'],
    ];

    public function up(): void
    {
        Schema::create('respaldo_regimen', function (Blueprint $table): void {
            $table->id();
            $table->string('tabla', 40);
            $table->uuid('fila_id');
            $table->string('columna', 40);
            $table->string('valor_anterior', 40)->nullable();
            $table->timestamp('migrado_en')->useCurrent();
        });
        foreach (self::LEYES as [$codigo, $nombre, $aplicaA]) {
            if (DB::table('regimenes_normativos')->where('codigo', $codigo)->doesntExist()) {
                $id = (string) Str::uuid7();
                DB::table('regimenes_normativos')->insert(['id' => $id, 'codigo' => $codigo, 'nombre' => $nombre, 'aplica_a' => $aplicaA, 'activo' => true]);
                DB::table('respaldo_regimen')->insert(['tabla' => 'regimenes_normativos', 'fila_id' => $id, 'columna' => 'creado', 'valor_anterior' => null]);
            }
        }
        Schema::table('vinculos_laborales', fn (Blueprint $table) => $table->string('regimen_laboral', 20)->nullable()->change());

        $vinculos = DB::table('vinculos_laborales')->where(fn ($consulta) => $consulta->whereNotIn('regimen_laboral', self::TIPOS))->whereNotNull('regimen_laboral')
            ->get(['id', 'regimen_laboral']);
        foreach ($vinculos as $vinculo) {
            DB::table('respaldo_regimen')->insert(['tabla' => 'vinculos_laborales', 'fila_id' => $vinculo->id, 'columna' => 'regimen_laboral', 'valor_anterior' => $vinculo->regimen_laboral]);
            DB::table('vinculos_laborales')->where('id', $vinculo->id)->update(['regimen_laboral' => null]);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE vinculos_laborales ADD CONSTRAINT vinculos_laborales_regimen_laboral_tipo CHECK (regimen_laboral IS NULL OR regimen_laboral IN ('carrera', 'plazo_determinado', 'designado'))");
        }

        $leyes = DB::table('regimenes_normativos')->get(['id', 'codigo', 'aplica_a'])->keyBy('codigo');
        $leyDeVinculos = DB::table('vinculos_laborales')->join('regimenes_normativos', 'regimenes_normativos.id', '=', 'vinculos_laborales.regimen_normativo_id')
            ->where('vinculos_laborales.anulado', false)->get(['vinculos_laborales.usuario_id', 'regimenes_normativos.codigo'])->groupBy('usuario_id');
        foreach (DB::table('usuarios')->whereNull('regimen_normativo_id')->get(['id', 'tipo_personal', 'regimen_laboral']) as $persona) {
            $ley = $leyes->get(self::ALIAS_LEY[$persona->regimen_laboral] ?? $persona->regimen_laboral);
            $deVinculos = $leyDeVinculos->get($persona->id, collect())->pluck('codigo')->unique();
            if (! $ley || $ley->aplica_a !== $persona->tipo_personal || $deVinculos->contains(fn (string $codigo): bool => $codigo !== $ley->codigo)) {
                continue;
            }
            DB::table('respaldo_regimen')->insert(['tabla' => 'usuarios', 'fila_id' => $persona->id, 'columna' => 'regimen_normativo_id', 'valor_anterior' => null]);
            DB::table('usuarios')->where('id', $persona->id)->update(['regimen_normativo_id' => $ley->id]);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vinculos_laborales DROP CONSTRAINT IF EXISTS vinculos_laborales_regimen_laboral_tipo');
        }
        foreach (DB::table('respaldo_regimen')->where('columna', '<>', 'creado')->orderByDesc('id')->get() as $respaldo) {
            DB::table($respaldo->tabla)->where('id', $respaldo->fila_id)->update([$respaldo->columna => $respaldo->valor_anterior]);
        }
        // Leyes que creó esta migración, solo si nadie las usa ya.
        foreach (DB::table('respaldo_regimen')->where('columna', 'creado')->pluck('fila_id') as $id) {
            if (DB::table('usuarios')->where('regimen_normativo_id', $id)->doesntExist() && DB::table('vinculos_laborales')->where('regimen_normativo_id', $id)->doesntExist()) {
                DB::table('regimenes_normativos')->where('id', $id)->delete();
            }
        }
        Schema::table('vinculos_laborales', fn (Blueprint $table) => $table->string('regimen_laboral', 20)->nullable(false)->change());
        Schema::drop('respaldo_regimen');
    }
};
