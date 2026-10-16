<?php

namespace Tests\Postgres;

use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use App\Models\EnvioVencimiento;
use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\SeccionLegajo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'escalafon_test' || config('database.connections.pgsql.host') !== 'testdb') {
            $this->fail('Estas pruebas destructivas solo se ejecutan en testdb/escalafon_test.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful()->execute();
        RefreshDatabaseState::$migrated = false;
    }

    public function test_two_accesses_produce_one_linear_audit_chain(): void
    {
        $documento = DocumentoUsuario::factory()->create();
        $actor = Usuario::factory()->create();
        $resultados = $this->concurrently('audit', $documento->id, $actor->id);
        $this->assertSame([0, 0], $resultados);
        $eventos = AuditoriaEvento::query()->orderBy('id')->get();
        $this->assertCount(2, $eventos);
        $this->assertNull($eventos[0]->hash_previo);
        $this->assertSame($eventos[0]->hash_registro, $eventos[1]->hash_previo);
    }

    public function test_two_uploads_cannot_reserve_the_same_folio(): void
    {
        $legajo = Legajo::factory()->create();
        SeccionLegajo::query()->firstOrCreate(['id' => 2], ['nombre' => 'Incorporación', 'orden' => 2]);
        $resultados = $this->concurrently('folio', $legajo->id);
        sort($resultados);
        $this->assertSame([0, 2], $resultados);
        $this->assertSame(1, LegajoSeccion::query()->where('legajo_id', $legajo->id)->value('ultimo_folio'));
    }

    public function test_two_workers_send_a_pending_notice_only_once(): void
    {
        $envio = EnvioVencimiento::factory()->create();
        $this->assertSame([0, 0], $this->concurrently('mail', $envio->id));
        $envio->refresh();
        $this->assertSame(1, $envio->intentos);
        $this->assertSame('simulado', $envio->estado);
    }

    private function concurrently(string ...$arguments): array
    {
        $processes = [];
        foreach ([1, 2] as $worker) {
            $process = new Process([PHP_BINARY, __DIR__.'/worker.php', ...$arguments], base_path(), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'testdb',
                'DB_DATABASE' => 'escalafon_test', 'DB_USERNAME' => 'escalafon_test',
                'DB_PASSWORD' => 'ci-only-password', 'DB_URL' => '', 'MAIL_MAILER' => 'array',
                'MAIL_FROM_ADDRESS' => 'pruebas@example.test', 'CACHE_STORE' => 'array',
            ]);
            $process->setTimeout(20)->start();
            $processes[] = $process;
        }

        return array_map(function (Process $process): int {
            $code = $process->wait();
            $this->assertContains($code, [0, 2], $process->getErrorOutput());

            return $code;
        }, $processes);
    }
}
