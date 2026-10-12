<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migración de las banderas a tres estados: los false pasan a Sin responder (null), los true se conservan, y al
 * revertir los null vuelven a false. Sin la transacción de RefreshDatabase: en SQLite change() reconstruye la tabla
 * y solo puede hacerlo con las claves foráneas desactivadas (PostgreSQL altera la columna en su lugar). Por eso el
 * test deja el esquema migrado y borra por id solo lo que crea.
 */
class BanderasTresEstadosMigracionTest extends TestCase
{
    use RefreshDatabase;

    /** Usa la base ya migrada del resto de la suite, sin transacción envolvente (ver arriba). */
    public function beginDatabaseTransaction(): void {}

    public function test_false_becomes_unanswered_true_is_kept_and_it_reverts(): void
    {
        $migracion = require database_path('migrations/2026_10_11_120000_banderas_tres_estados.php');
        $ejecutar = function (string $paso) use ($migracion): void {
            Schema::withoutForeignKeyConstraints(fn () => $migracion->{$paso}());
        };
        $ejecutar('down');
        try {
            $this->comprobar($ejecutar);
        } finally {
            // Deja el esquema migrado (con_discapacidad nulable), haya fallado o no a mitad de camino.
            if (! collect(Schema::getColumns('usuarios'))->firstWhere('name', 'con_discapacidad')['nullable']) {
                $ejecutar('up');
            }
            // Solo lo que creó este test (por id): las tablas no se vacían, otras migraciones siembran datos (p. ej. leyes).
            DB::table('vinculos_laborales')->whereIn('id', $this->creados['vinculos_laborales'])->delete();
            DB::table('regimenes_normativos')->whereIn('id', $this->creados['regimenes_normativos'])->delete();
            DB::table('usuarios')->whereIn('id', $this->creados['usuarios'])->delete();
        }
    }

    /** @var array<string, list<string>> Filas creadas por el test, para borrarlas por id. */
    private array $creados = ['vinculos_laborales' => [], 'regimenes_normativos' => [], 'usuarios' => []];

    private function comprobar(\Closure $ejecutar): void
    {
        $id = Usuario::factory()->create(['obligado_djbr' => true])->id;
        $this->creados['usuarios'][] = $id;
        $creado = VinculoLaboral::factory()->create(['usuario_id' => $id, 'tiene_periodo_prueba' => true]);
        $vinculo = $creado->id;
        $this->creados['vinculos_laborales'][] = $vinculo;
        $this->creados['regimenes_normativos'][] = $creado->regimen_normativo_id;
        $usuario = fn (): array => (array) DB::table('usuarios')->where('id', $id)->first(['obligado_djbr', 'exige_colegiatura', 'tiene_derechohabientes', 'con_discapacidad']);
        $enVinculo = fn (): array => (array) DB::table('vinculos_laborales')->where('id', $vinculo)->first(['obligado_djbr', 'tiene_periodo_prueba']);
        $this->assertEquals(['obligado_djbr' => 1, 'exige_colegiatura' => 0, 'tiene_derechohabientes' => 0, 'con_discapacidad' => 0], $usuario());

        $ejecutar('up');
        $this->assertEquals(['obligado_djbr' => 1, 'exige_colegiatura' => null, 'tiene_derechohabientes' => null, 'con_discapacidad' => null], $usuario());
        $this->assertEquals(['obligado_djbr' => null, 'tiene_periodo_prueba' => 1], $enVinculo());
        // El índice por expresión del correo se conserva.
        $this->assertNotNull(collect(DB::select("select name from sqlite_master where type = 'index' and name = 'usuarios_email_lower_unique'"))->first());

        $ejecutar('down');
        $this->assertEquals(['obligado_djbr' => 1, 'exige_colegiatura' => 0, 'tiene_derechohabientes' => 0, 'con_discapacidad' => 0], $usuario());
        $this->assertEquals(['obligado_djbr' => 0, 'tiene_periodo_prueba' => 1], $enVinculo());
    }
}
