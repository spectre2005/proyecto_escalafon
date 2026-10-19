<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Support\Documentos\AlmacenDocumento;
use App\Support\Documentos\AnalizadorAntivirus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class ProteccionArchivosTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararDemo();
        $this->actuarComo();
    }

    private function cargar(): DocumentoUsuario
    {
        $id = $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('a'), 1, 2))->assertCreated()->json('data.id');

        return DocumentoUsuario::query()->with('archivo')->findOrFail($id);
    }

    public function test_new_files_are_scanned_encrypted_and_delivered_as_the_original_pdf(): void
    {
        $doc = $this->cargar();
        $archivo = $doc->archivo;
        $cifrado = Storage::disk($archivo->almacenamiento)->get($archivo->storage_path);
        $this->assertTrue($archivo->cifrado);
        $this->assertSame('limpio', $archivo->estado_antivirus);
        $this->assertStringNotContainsString('%PDF-', $cifrado);
        $contenido = $this->get('/api/v1/expedientes/'.$doc->usuario_id.'/documentos/'.$doc->id.'/archivo')->assertOk()->streamedContent();
        $this->assertStringStartsWith('%PDF-', $contenido);
        $this->assertSame($archivo->hash_sha256, hash('sha256', $contenido));
        $this->assertSame($archivo->tamano_bytes, strlen($contenido));
    }

    public function test_infection_or_unavailability_does_not_store_a_document_or_reserve_folios(): void
    {
        $this->mock(AnalizadorAntivirus::class)->shouldReceive('analizar')->andThrow(ValidationException::withMessages(['pdf' => 'Amenaza detectada.']));
        $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('a'), 1, 2))->assertUnprocessable();
        $this->assertDatabaseCount('archivos_documento', 0);
        $this->mock(AnalizadorAntivirus::class)->shouldReceive('analizar')->andThrow(new HttpException(503, 'Antivirus no disponible.'));
        $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('a'), 1, 2))->assertStatus(503);
        $this->assertDatabaseCount('archivos_documento', 0);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_tampering_and_missing_keys_fail_closed_and_old_keys_support_rotation(): void
    {
        $doc = $this->cargar();
        $url = '/api/v1/expedientes/'.$doc->usuario_id.'/documentos/'.$doc->id.'/archivo';
        $original = config('documentos.clave');
        config(['documentos.clave' => 'base64:'.base64_encode(str_repeat('n', 32))]);
        $this->getJson($url)->assertStatus(503);
        config(['documentos.claves_anteriores' => [$original]]);
        $this->get($url)->assertOk();
        Storage::disk($doc->archivo->almacenamiento)->put($doc->archivo->storage_path, 'Alterado');
        $this->getJson($url)->assertStatus(503);
        config(['documentos.clave' => 'incorrecta']);
        $this->getJson($url)->assertStatus(503);
    }

    public function test_pending_legacy_files_are_scanned_and_infections_block_every_section(): void
    {
        $doc = $this->cargar();
        $archivo = $doc->archivo;
        $contenido = AlmacenDocumento::leer($archivo);
        Storage::disk($archivo->almacenamiento)->put($archivo->storage_path, $contenido);
        $archivo->update(['cifrado' => false, 'estado_antivirus' => 'pendiente']);
        $this->get('/api/v1/expedientes/'.$doc->usuario_id.'/documentos/'.$doc->id.'/archivo')->assertOk();
        $this->assertSame('limpio', $archivo->refresh()->estado_antivirus);
        $archivo->update(['estado_antivirus' => 'pendiente']);
        $this->mock(AnalizadorAntivirus::class)->shouldReceive('analizar')->andThrow(ValidationException::withMessages(['pdf' => 'Amenaza.']));
        $this->getJson('/api/v1/expedientes/'.$doc->usuario_id.'/documentos/'.$doc->id.'/archivo')->assertStatus(423);
        $this->assertSame('infectado', $archivo->refresh()->estado_antivirus);
    }

    public function test_private_key_command_never_replaces_or_prints_an_existing_key(): void
    {
        config(['documentos.clave' => null, 'documentos.archivo_clave' => storage_path('app/keys/prueba.key')]);
        $this->artisan('documentos:clave')->assertSuccessful();
        $clave = file_get_contents(config('documentos.archivo_clave'));
        $this->assertSame(44, strlen(substr($clave, 7)));
        $this->artisan('documentos:clave')->expectsOutput('La clave ya existe; se conserva sin cambios.')->assertSuccessful();
        $this->assertSame($clave, file_get_contents(config('documentos.archivo_clave')));
    }

    public function test_conversion_verifies_old_content_before_removing_plaintext_and_is_repeatable(): void
    {
        $doc = $this->cargar();
        $archivo = $doc->archivo;
        $contenido = AlmacenDocumento::leer($archivo);
        $anterior = $archivo->storage_path;
        Storage::disk($archivo->almacenamiento)->put($anterior, $contenido);
        $archivo->update(['cifrado' => false, 'estado_antivirus' => 'pendiente']);
        $this->assertTrue(AlmacenDocumento::proteger($archivo));
        $archivo->refresh();
        $this->assertTrue($archivo->cifrado);
        Storage::disk($archivo->almacenamiento)->assertMissing($anterior);
        $this->assertSame($contenido, AlmacenDocumento::leer($archivo));
        $this->assertFalse(AlmacenDocumento::proteger($archivo));
        $protegido = AlmacenDocumento::cifrarAnterior($contenido);
        $this->assertStringNotContainsString('%PDF-', $protegido);
        $this->assertSame($contenido, AlmacenDocumento::leerAnterior($protegido));
    }

    public function test_real_clamav_rejects_the_harmless_eicar_test_signature(): void
    {
        if (getenv('ANTIVIRUS_INTEGRACION') !== '1') {
            $this->markTestSkipped('Prueba de integración opcional: requiere el servicio ClamAV.');
        }
        $antivirus = new AnalizadorAntivirus;
        $antivirus->analizar('%PDF-1.4 documento ficticio sin amenaza');
        $eicar = base64_decode('WDVPIVAlQEFQWzRcUFpYNTQoUF4pN0NDKTd9JEVJQ0FSLVNUQU5EQVJELUFOVElWSVJVUy1URVNULUZJTEUhJEgrSCo=');
        $this->expectException(ValidationException::class);
        $antivirus->analizar($eicar);
    }
}
