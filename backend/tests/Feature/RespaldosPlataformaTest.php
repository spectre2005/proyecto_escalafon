<?php

namespace Tests\Feature;

use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use App\Models\Plataforma\Respaldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/**
 * Respaldos por institución: qué respalda el script (plataforma:respaldo-plan), el registro de cada resultado
 * (plataforma:registrar-respaldo) y lo que muestra el panel (último respaldo y aviso si no hay uno reciente).
 */
class RespaldosPlataformaTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const CARPETA = '20261017-020000';

    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @return list<list<string>> */
    private function plan(): array
    {
        Artisan::call('plataforma:respaldo-plan');

        return collect(explode("\n", trim(Artisan::output())))->filter(fn (string $l): bool => str_contains($l, "\t"))
            ->map(fn (string $l): array => explode("\t", $l))->values()->all();
    }

    private function registrar(array $opciones, string $objetivo = 'uno'): int
    {
        return Artisan::call('plataforma:registrar-respaldo', ['objetivo' => $objetivo, 'carpeta' => self::CARPETA, ...$opciones]);
    }

    public function test_the_plan_lists_each_provisioned_institution_with_its_database_and_folder(): void
    {
        // La conexión de la plataforma ya está abierta (SQLite); solo se cambia lo que el plan lee de la configuración.
        config(['database.connections.plataforma.driver' => 'pgsql', 'database.connections.plataforma.database' => 'escalafon_plataforma',
            'database.connections.pgsql.database' => 'escalafon']);
        $this->institucion(['slug' => 'uno', 'estado' => Institucion::ACTIVA, 'base_datos' => 'escalafon_uno', 'aprovisionada_en' => now()]);
        $this->institucion(['slug' => 'dos', 'estado' => Institucion::SUSPENDIDA, 'base_datos' => 'escalafon_dos', 'aprovisionada_en' => now()]);
        $this->institucion(['slug' => 'nueva', 'base_datos' => 'escalafon_nueva']);

        $this->assertSame([
            ['plataforma', 'escalafon_plataforma', '-'],
            ['dos', 'escalafon_dos', 'instituciones/dos'],
            ['uno', 'escalafon_uno', 'instituciones/uno'],
            ['principal', 'escalafon', '.'],
        ], $this->plan(), 'La suspendida también se respalda; la registrada sin aprovisionar aún no tiene base.');

        // Si la base del .env ya es de una institución, no se repite como «principal».
        $this->institucion(['slug' => 'uniq', 'estado' => Institucion::ACTIVA, 'base_datos' => 'escalafon', 'aprovisionada_en' => now()]);
        $this->assertNotContains('principal', array_column($this->plan(), 0));
    }

    public function test_the_plan_never_emits_names_that_are_unsafe_for_the_shell(): void
    {
        config(['database.connections.pgsql.database' => 'escalafon']);
        Institucion::query()->create(['nombre' => 'Mala', 'slug' => 'mala', 'estado' => Institucion::ACTIVA, 'base_datos' => 'x; rm -rf /',
            'almacenamiento' => 'instituciones/mala', 'aprovisionada_en' => now()]);

        $this->assertSame(0, Artisan::call('plataforma:respaldo-plan'));
        $this->assertStringNotContainsString('rm -rf', Artisan::output());
        $this->assertSame([['principal', 'escalafon', '.']], $this->plan());
    }

    public function test_a_backup_result_is_recorded_once_per_target_and_folder(): void
    {
        $uno = $this->institucion(['slug' => 'uno', 'estado' => Institucion::ACTIVA, 'aprovisionada_en' => now()]);

        $this->assertSame(0, $this->registrar(['--estado' => 'correcto', '--base' => 'escalafon_uno', '--base-bytes' => 1200, '--base-sha256' => self::SHA]));
        $this->assertSame(0, $this->registrar(['--estado' => 'correcto', '--base' => 'escalafon_uno', '--base-bytes' => 1200, '--base-sha256' => self::SHA, '--copia-externa' => 'correcta']));

        $respaldo = Respaldo::query()->sole();
        $this->assertSame([$uno->id, 'correcto', 'correcta', 1200], [$respaldo->institucion_id, $respaldo->estado, $respaldo->copia_externa, $respaldo->base_bytes]);
        $this->assertSame(0, AuditoriaPlataforma::query()->count(), 'Un respaldo correcto no llena la auditoría.');

        $this->assertSame(0, $this->registrar(['--estado' => 'correcto'], 'plataforma'));
        $this->assertNull(Respaldo::query()->where('objetivo', 'plataforma')->value('institucion_id'));
    }

    public function test_failures_are_audited_and_invalid_data_is_rejected(): void
    {
        $uno = $this->institucion(['slug' => 'uno', 'estado' => Institucion::ACTIVA, 'aprovisionada_en' => now()]);

        $this->registrar(['--estado' => 'correcto', '--copia-externa' => 'fallida', '--detalle' => 'El destino no respondió.']);
        $this->assertSame(['respaldo.fallido'], AuditoriaPlataforma::query()->where('institucion_id', $uno->id)->pluck('accion')->all());

        foreach ([
            [['--estado' => 'quizas'], 'uno'], [['--estado' => 'correcto', '--base-sha256' => 'corto'], 'uno'],
            [['--estado' => 'correcto', '--copia-externa' => 'sin_cifrar'], 'uno'], [['--estado' => 'correcto'], 'no-existe'],
            [['--estado' => 'correcto', '--base' => 'x; rm -rf /'], 'uno'], [[], 'uno'],
        ] as [$opciones, $objetivo]) {
            $this->assertSame(1, $this->registrar($opciones, $objetivo), json_encode($opciones));
        }
        $this->assertSame(1, Artisan::call('plataforma:registrar-respaldo', ['objetivo' => 'uno', 'carpeta' => '2026-10-17', '--estado' => 'correcto']));
        $this->assertSame(1, Respaldo::query()->count());
    }

    public function test_a_restore_check_updates_an_existing_backup_only(): void
    {
        $uno = $this->institucion(['slug' => 'uno', 'estado' => Institucion::ACTIVA, 'aprovisionada_en' => now()]);
        $this->assertSame(1, $this->registrar(['--verificacion' => 'correcta']), 'No hay respaldo que verificar.');

        $this->registrar(['--estado' => 'correcto']);
        $this->assertSame(0, $this->registrar(['--verificacion' => 'correcta']));
        $this->assertSame('correcta', Respaldo::query()->sole()->verificacion);
        $this->assertNotNull(Respaldo::query()->sole()->verificado_en);

        $this->registrar(['--verificacion' => 'fallida', '--detalle' => 'pg_restore terminó con error.']);
        $this->assertSame('fallida', Respaldo::query()->sole()->verificacion);
        $this->assertSame(['respaldo.verificacion_fallida'], AuditoriaPlataforma::query()->where('institucion_id', $uno->id)->pluck('accion')->all());
    }

    public function test_the_panel_shows_the_last_backup_and_warns_when_it_is_old(): void
    {
        $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
        $uno = $this->institucion(['nombre' => 'Uno', 'slug' => 'uno', 'estado' => Institucion::ACTIVA, 'aprovisionada_en' => now()]);
        $dos = $this->institucion(['nombre' => 'Dos', 'slug' => 'dos', 'estado' => Institucion::ACTIVA, 'aprovisionada_en' => now()]);
        $this->institucion(['nombre' => 'Sin aprovisionar', 'slug' => 'nueva']);

        // uno: correcto hace 2 horas. dos: el último falló y el correcto anterior es de hace 3 días.
        $this->travel(-3)->days();
        $this->registrar(['--estado' => 'correcto'], 'dos');
        $this->travelBack();
        $this->travel(-2)->hours();
        $this->registrar(['--estado' => 'correcto', '--copia-externa' => 'correcta']);
        $this->travelBack();
        Artisan::call('plataforma:registrar-respaldo', ['objetivo' => 'dos', 'carpeta' => '20261017-030000', '--estado' => 'fallido', '--detalle' => 'pg_dump falló.']);
        $this->registrar(['--estado' => 'correcto'], 'plataforma');

        $this->getJson("/api/plataforma/instituciones/{$uno->id}")->assertOk()
            ->assertJsonPath('data.respaldo.atrasado', false)->assertJsonPath('data.respaldo.ultimo.copia_externa', 'correcta')
            ->assertJsonCount(1, 'data.respaldos');
        $this->getJson("/api/plataforma/instituciones/{$dos->id}")->assertOk()
            ->assertJsonPath('data.respaldo.atrasado', true)->assertJsonPath('data.respaldo.ultimo.estado', 'fallido')
            ->assertJsonPath('data.respaldos.0.detalle', 'pg_dump falló.')->assertJsonMissingPath('data.respaldos.0.base_sha256');

        $resumen = $this->getJson('/api/plataforma/dashboard')->assertOk();
        $this->assertSame(['Dos'], array_column($resumen->json('data.respaldos.atrasados'), 'nombre'), 'La no aprovisionada aún no tiene qué respaldar.');
        $this->assertSame('correcto', $resumen->json('data.respaldos.plataforma.estado'));

        $listado = collect($this->getJson('/api/plataforma/instituciones')->assertOk()->json('data'))->keyBy('slug');
        $this->assertFalse($listado['uno']['respaldo']['atrasado']);
        $this->assertTrue($listado['dos']['respaldo']['atrasado']);
        $this->assertFalse($listado['nueva']['respaldo']['atrasado']);
    }
}
