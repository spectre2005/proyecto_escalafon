<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\Antivirus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AntivirusDocumentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_infected_files_are_blocked_in_every_section_even_in_local_mode(): void
    {
        $this->actuarComo();
        Storage::fake('documentos');
        config(['antivirus.enforce' => false]);
        $persona = Usuario::factory()->create();
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id]);
        foreach (range(1, 13) as $seccion) {
            $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => $seccion,
                'codigo' => $seccion === 9 ? 'S09_C_AFILIACION_SINDICATO' : 'AV_'.$seccion]);
            $archivo = ArchivoDocumento::factory()->create(['almacenamiento' => 'documentos', 'storage_path' => 'test-'.$seccion.'.pdf', 'estado_antivirus' => 'infectado']);
            Storage::disk('documentos')->put($archivo->storage_path, '%PDF-1.4');
            $doc = DocumentoUsuario::factory()->create(['usuario_id' => $persona->id, 'legajo_id' => $legajo->id,
                'tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => $seccion, 'archivo_id' => $archivo->id]);
            $this->get('/api/v1/expedientes/'.$persona->id.'/documentos/'.$doc->id.'/archivo')->assertStatus(423);
        }
    }

    public function test_strict_mode_scans_pending_files_and_never_serves_an_error(): void
    {
        $this->actuarComo();
        Storage::fake('documentos');
        config(['antivirus.enforce' => true]);
        $persona = Usuario::factory()->create();
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id]);
        $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => 2]);
        $scanner = $this->mock(Antivirus::class)->makePartial();
        $scanner->shouldReceive('archivo')->times(3)->andReturn('error', 'pendiente', 'limpio');
        $archivo = ArchivoDocumento::factory()->create(['almacenamiento' => 'documentos', 'storage_path' => 'pending.pdf', 'estado_antivirus' => 'pendiente']);
        Storage::disk('documentos')->put('pending.pdf', '%PDF-1.4');
        $doc = DocumentoUsuario::factory()->create(['usuario_id' => $persona->id, 'legajo_id' => $legajo->id,
            'tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => 2, 'archivo_id' => $archivo->id]);
        $url = '/api/v1/expedientes/'.$persona->id.'/documentos/'.$doc->id.'/archivo';
        $this->get($url)->assertStatus(423);
        $this->assertSame('error', $archivo->refresh()->estado_antivirus);
        $this->get($url)->assertStatus(423);
        $this->get($url)->assertOk();
        $this->assertSame('limpio', $archivo->refresh()->estado_antivirus);
    }

    public function test_unavailable_scanner_returns_error_without_serving_content(): void
    {
        config(['antivirus.enabled' => true, 'antivirus.endpoint' => 'tcp://127.0.0.1:1', 'antivirus.timeout_seconds' => 1]);
        Storage::fake('documentos');
        Storage::disk('documentos')->put('test.pdf', '%PDF-1.4');
        $this->assertSame('error', app(Antivirus::class)->archivo('documentos', 'test.pdf'));
    }

    public function test_strict_mode_cannot_accept_evidence_before_a_clean_scan(): void
    {
        $this->actuarComo();
        config(['antivirus.enforce' => true]);
        $persona = Usuario::factory()->create();
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id]);
        $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => 3, 'requiere_fedateo' => false]);
        $archivo = ArchivoDocumento::factory()->create(['estado_antivirus' => 'pendiente']);
        $doc = DocumentoUsuario::factory()->create(['usuario_id' => $persona->id, 'legajo_id' => $legajo->id,
            'tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => 3, 'archivo_id' => $archivo->id, 'estado' => 'pendiente']);
        $this->postJson('/api/v1/expedientes/'.$persona->id.'/seccion-03/documentos/'.$doc->id.'/estado', [
            'estado' => 'aceptado', 'estado_anterior' => 'pendiente', 'recepcion_confirmada' => true,
        ])->assertUnprocessable();
        $this->assertSame('pendiente', $doc->refresh()->estado);
    }
}
