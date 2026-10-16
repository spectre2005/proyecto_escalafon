<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use App\Models\Usuario;
use App\Support\Documentos\AuditoriaDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class AuditoriaTransaccionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_opens_transaction_and_chains_access_events(): void
    {
        $documento = DocumentoUsuario::factory()->create();
        $request = Request::create('/api/v1/expedientes/'.$documento->usuario_id, 'GET');
        $usuario = Usuario::factory()->create();
        $request->setUserResolver(fn () => $usuario);
        $niveles = [];
        Event::listen('eloquent.creating: '.AuditoriaEvento::class, function () use (&$niveles): void {
            $niveles[] = DB::transactionLevel();
        });
        $this->assertSame(1, DB::transactionLevel());
        AuditoriaDocumento::registrar($documento, $request, 'ver');
        AuditoriaDocumento::registrar($documento, $request, 'descargar');
        $eventos = AuditoriaEvento::query()->orderBy('id')->get();
        $this->assertSame([2, 2], $niveles);
        $this->assertSame($eventos[0]->hash_registro, $eventos[1]->hash_previo);
        $this->assertSame(1, DB::transactionLevel());
    }

    public function test_failed_audit_rolls_back_and_releases_transaction(): void
    {
        $documento = DocumentoUsuario::factory()->create();
        $request = Request::create('/');
        $usuario = Usuario::factory()->create();
        $request->setUserResolver(fn () => $usuario);
        Event::listen('eloquent.created: '.AuditoriaEvento::class, function (): never {
            throw new RuntimeException('Fallo después de insertar');
        });
        try {
            AuditoriaDocumento::registrar($documento, $request, 'ver');
            $this->fail('Debió fallar la auditoría.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo después de insertar', $exception->getMessage());
        }
        $this->assertDatabaseCount('auditoria_eventos', 0);
        $this->assertSame(1, DB::transactionLevel());
    }
}
