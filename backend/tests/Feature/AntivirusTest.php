<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Support\Antivirus\AntivirusNoDisponible;
use App\Support\Antivirus\ClamAv;
use App\Support\Antivirus\EscanerAntivirus;
use App\Support\Antivirus\SinAntivirus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SubeDocumentos;
use Tests\Fixtures\AntivirusFalso;
use Tests\TestCase;

/** Todo archivo se revisa antes de guardarse; infectado o sin antivirus disponible, no entra. */
class AntivirusTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararDemo();
    }

    private function pdfInfectado(): UploadedFile
    {
        $contenido = "%PDF-1.4\n1 0 obj<</Type/Pages/Count 1>>endobj\n%".AntivirusFalso::EICAR."\n%%EOF\n";

        return UploadedFile::fake()->createWithContent('infectado.pdf', $contenido);
    }

    public function test_clean_pdf_is_scanned_and_saved_as_clean(): void
    {
        $this->actuarComo();
        $id = $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('a'), 1, 2))->assertCreated()->json('data.id');

        $this->assertSame(1, $this->antivirus->revisados);
        $this->assertSame('limpio', ArchivoDocumento::query()->whereHas('documentosUsuario', fn ($q) => $q->whereKey($id))->value('estado_antivirus'));
    }

    public function test_infected_pdf_is_rejected_and_never_reaches_the_disk(): void
    {
        $this->actuarComo();
        $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('a'), 1, 2, ['pdf' => $this->pdfInfectado()]))
            ->assertUnprocessable()->assertJsonValidationErrors(['pdf' => 'amenaza']);

        $this->assertDatabaseCount('archivos_documento', 0);
        $this->assertDatabaseCount('documentos_usuario', 0);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_upload_is_rejected_when_the_antivirus_is_down(): void
    {
        $this->actuarComo();
        $this->antivirus->caido = true;
        $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('a'), 1, 2))
            ->assertStatus(503)->assertJsonPath('message', fn (string $mensaje): bool => str_contains($mensaje, 'antivirus no está disponible'));

        $this->assertDatabaseCount('archivos_documento', 0);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_command_scans_pending_files_and_marks_infected_ones(): void
    {
        $disco = Storage::disk(config('legajo.disco_documentos'));
        $disco->put('legajos/a.pdf', '%PDF-1.4 limpio');
        $disco->put('legajos/b.pdf', '%PDF-1.4 '.AntivirusFalso::EICAR);
        $base = ['almacenamiento' => config('legajo.disco_documentos'), 'estado_antivirus' => 'pendiente'];
        $limpio = ArchivoDocumento::factory()->create([...$base, 'storage_path' => 'legajos/a.pdf']);
        $infectado = ArchivoDocumento::factory()->create([...$base, 'storage_path' => 'legajos/b.pdf']);
        $faltante = ArchivoDocumento::factory()->create([...$base, 'storage_path' => 'legajos/no-existe.pdf']);
        $yaLimpio = ArchivoDocumento::factory()->create([...$base, 'storage_path' => 'legajos/b.pdf', 'estado_antivirus' => 'limpio']);

        $this->artisan('antivirus:escanear')->expectsOutputToContain('1 limpio(s), 1 infectado(s), 1 sin archivo')->assertSuccessful();
        $this->assertSame(['limpio', 'infectado', 'pendiente', 'limpio'],
            [$limpio->fresh()->estado_antivirus, $infectado->fresh()->estado_antivirus, $faltante->fresh()->estado_antivirus, $yaLimpio->fresh()->estado_antivirus]);
        // Con --todos se revisan también los ya limpios (firmas nuevas).
        $this->artisan('antivirus:escanear --todos')->assertSuccessful();
        $this->assertSame('infectado', $yaLimpio->fresh()->estado_antivirus);
        // El contenido del PDF no cambia: solo el estado del antivirus.
        $this->assertStringContainsString('EICAR', $disco->get('legajos/b.pdf'));
    }

    public function test_command_fails_without_changes_when_the_antivirus_is_down_or_not_configured(): void
    {
        $disco = Storage::disk(config('legajo.disco_documentos'));
        $disco->put('legajos/a.pdf', '%PDF-1.4');
        $archivo = ArchivoDocumento::factory()->create(['almacenamiento' => config('legajo.disco_documentos'), 'storage_path' => 'legajos/a.pdf', 'estado_antivirus' => 'pendiente']);

        $this->antivirus->caido = true;
        $this->artisan('antivirus:escanear')->assertFailed();
        $this->app->instance(EscanerAntivirus::class, new SinAntivirus);
        $this->artisan('antivirus:escanear')->assertFailed();
        $this->assertSame('pendiente', $archivo->fresh()->estado_antivirus);
    }

    public function test_without_engine_files_stay_pending_but_production_always_uses_clamav(): void
    {
        $this->app->instance(EscanerAntivirus::class, new SinAntivirus);
        $this->actuarComo();
        $id = $this->subir($this->personaDemo(), $this->metadatos($this->tipo02('a'), 1, 2))->assertCreated()->json('data.id');
        $this->assertSame('pendiente', ArchivoDocumento::query()->whereHas('documentosUsuario', fn ($q) => $q->whereKey($id))->value('estado_antivirus'));

        // Sin la instancia de la prueba, se resuelve con el enlace de AppServiceProvider.
        $this->app->forgetInstance(EscanerAntivirus::class);
        config(['antivirus.driver' => 'ninguno']);
        $this->assertInstanceOf(SinAntivirus::class, app(EscanerAntivirus::class));
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->assertInstanceOf(ClamAv::class, app(EscanerAntivirus::class));
    }

    public function test_clamd_responses_are_interpreted_strictly(): void
    {
        $this->assertNull(ClamAv::interpretar('stream: OK'));
        $this->assertSame('Win.Test.EICAR_HDB-1', ClamAv::interpretar('stream: Win.Test.EICAR_HDB-1 FOUND'));
        foreach (['', 'stream: OK extra', 'INSTREAM size limit exceeded. ERROR', 'PONG'] as $respuesta) {
            try {
                ClamAv::interpretar($respuesta);
                $this->fail("Respuesta aceptada: {$respuesta}");
            } catch (AntivirusNoDisponible) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unreachable_clamd_is_reported_as_unavailable(): void
    {
        // Puerto 1 en localhost: nadie escucha, la conexión se rechaza al instante.
        $clamav = new ClamAv('127.0.0.1', 1, 1, 1);
        $this->assertFalse($clamav->enLinea());
        $this->expectException(AntivirusNoDisponible::class);
        $clamav->escanearContenido('%PDF-1.4');
    }
}
