<?php

namespace Tests\Feature;

use App\Models\RegimenNormativo;
use Database\Seeders\RegimenNormativoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Migración del régimen al vínculo: los tipos de vínculo que no son carrera, plazo determinado ni designado (p. ej. «276»)
 * pasan a Sin definir (NULL) y la ley de la persona se completa solo si corresponde a su tipo de personal y no choca con
 * la de sus vínculos. El down lo restaura todo desde respaldo_regimen. Sin la transacción de RefreshDatabase: en SQLite
 * change() reconstruye la tabla con las claves foráneas desactivadas. El test deja el esquema migrado y borra por id solo
 * lo que crea.
 */
class RegimenVinculoMigracionTest extends TestCase
{
    use RefreshDatabase;

    /** Usa la base ya migrada del resto de la suite, sin transacción envolvente (ver arriba). */
    public function beginDatabaseTransaction(): void {}

    /** @var array<string, list<string>> */
    private array $creados = ['vinculos_laborales' => [], 'usuarios' => []];

    private object $migracion;

    public function test_undefined_types_and_completed_laws_are_reverted_by_down(): void
    {
        $this->migracion = require database_path('migrations/2026_10_11_140000_regimen_tipo_de_vinculo.php');
        $this->paso('down');
        try {
            $this->comprobar();
        } finally {
            if (! Schema::hasTable('respaldo_regimen')) {
                $this->paso('up');
            }
            DB::table('vinculos_laborales')->whereIn('id', $this->creados['vinculos_laborales'])->delete();
            DB::table('usuarios')->whereIn('id', $this->creados['usuarios'])->delete();
        }
    }

    private function paso(string $paso): void
    {
        Schema::withoutForeignKeyConstraints(fn () => $this->migracion->{$paso}());
    }

    private function persona(string $tipo, string $codigo, ?string $ley = null): string
    {
        $id = (string) Str::uuid7();
        DB::table('usuarios')->insert(['id' => $id, 'numero_doc_identidad' => (string) random_int(10000000, 99999999), 'apellido_paterno' => 'Migración', 'nombres' => 'Prueba',
            'email_institucional' => "migracion.$id@example.test", 'password' => 'x', 'tipo_personal' => $tipo, 'regimen_laboral' => $codigo, 'regimen_normativo_id' => $ley]);
        $this->creados['usuarios'][] = $id;

        return $id;
    }

    private function vinculo(string $usuario, string $tipoVinculo, string $ley, string $tipo = 'administrativo'): string
    {
        $id = (string) Str::uuid7();
        DB::table('vinculos_laborales')->insert(['id' => $id, 'usuario_id' => $usuario, 'tipo_personal' => $tipo, 'regimen_laboral' => $tipoVinculo,
            'regimen_normativo_id' => $ley, 'nombre_puesto' => 'Puesto', 'fecha_inicio' => '2026-01-01']);
        $this->creados['vinculos_laborales'][] = $id;

        return $id;
    }

    private function comprobar(): void
    {
        // Esquema anterior: catálogo mínimo (el down quitó las leyes que había creado la migración) y datos como los reales.
        (new RegimenNormativoSeeder)->run();
        $ley = fn (string $codigo): string => RegimenNormativo::query()->where('codigo', $codigo)->value('id');
        $sinVinculo = $this->persona('administrativo', 'CAS');
        $choca = $this->persona('administrativo', '728');
        $docenteConCas = $this->persona('docente', 'CAS');
        $noEsLey = $this->persona('administrativo', 'plazo_determinado');
        $coincide = $this->persona('administrativo', '276');
        $yaTieneLey = $this->persona('administrativo', '276', $ley('1057'));
        $antiguos = [$this->vinculo($choca, '276', $ley('276')), $this->vinculo($coincide, '276', $ley('276')),
            $this->vinculo($docenteConCas, '276', $ley('30220'), 'docente'), $this->vinculo($yaTieneLey, '276', $ley('1057'))];
        $definido = $this->vinculo($coincide, 'carrera', $ley('276'));
        $tipos = fn (): array => DB::table('vinculos_laborales')->whereIn('id', [...$antiguos, $definido])->orderBy('id')->pluck('regimen_laboral', 'id')->all();
        $leyes = fn (): array => DB::table('usuarios')->whereIn('id', [$sinVinculo, $choca, $docenteConCas, $noEsLey, $coincide, $yaTieneLey])->pluck('regimen_normativo_id', 'id')->all();
        $antes = [$tipos(), $leyes()];

        $this->paso('up');
        $this->assertSame([null, null, null, null], array_values(array_intersect_key($tipos(), array_flip($antiguos))));
        $this->assertSame('carrera', $tipos()[$definido]);
        $this->assertSame([$sinVinculo => $ley('1057'), $choca => null, $docenteConCas => null, $noEsLey => null, $coincide => $ley('276'), $yaTieneLey => $ley('1057')],
            array_replace([$sinVinculo => 0, $choca => 0, $docenteConCas => 0, $noEsLey => 0, $coincide => 0, $yaTieneLey => 0], $leyes()));
        $this->assertSame(6, DB::table('respaldo_regimen')->count());
        $this->assertTrue(collect(Schema::getColumns('vinculos_laborales'))->firstWhere('name', 'regimen_laboral')['nullable']);

        // down: los 4 «276» vuelven a su valor y las dos leyes completadas vuelven a vacío.
        $this->paso('down');
        $this->assertSame($antes, [$tipos(), $leyes()]);
        $this->assertSame(['276', '276', '276', '276'], array_values(array_intersect_key($tipos(), array_flip($antiguos))));
        $this->assertFalse(Schema::hasTable('respaldo_regimen'));
        $this->assertFalse(collect(Schema::getColumns('vinculos_laborales'))->firstWhere('name', 'regimen_laboral')['nullable']);

        $this->paso('up');
        $this->assertSame([null, null, null, null], array_values(array_intersect_key($tipos(), array_flip($antiguos))));
    }
}
