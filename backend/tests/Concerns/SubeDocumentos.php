<?php

namespace Tests\Concerns;

use App\Models\DocumentoUsuario;
use App\Models\HistorialEstadoDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Database\Seeders\Seccion02CatalogoSeeder;
use Database\Seeders\Seccion02DemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/** Ayudas para subir documentos de la sección 02 con las personas de Seccion02DemoSeeder y un disco falso. */
trait SubeDocumentos
{
    /** Prepara un documento del flujo anterior para probar revisiones de registros históricos. */
    protected function documentoAnteriorPendiente(string $id, string $estado = 'pendiente'): void
    {
        $documento = DocumentoUsuario::query()->findOrFail($id);
        if ($documento->archivo_id === null) {
            return;
        }
        $this->assertSame('aceptado', $documento->estado);
        $documento->update(['estado' => $estado]);
        HistorialEstadoDocumento::query()->where('documento_usuario_id', $id)->whereNull('estado_anterior')->update(['estado_nuevo' => $estado]);
    }

    protected function prepararDemo(): void
    {
        Storage::fake((string) config('legajo.disco_documentos'));
        $this->seed(Seccion02DemoSeeder::class);
    }

    /** 99000201: con legajo, vínculo vigente, adendas, inducciones y período de prueba. 99000202: docente sin prueba. */
    protected function personaDemo(string $documento = '99000201'): Usuario
    {
        return Usuario::query()->where('numero_doc_identidad', $documento)->firstOrFail();
    }

    protected function tipo02(string $literal): TipoDocumento
    {
        $codigo = collect(Seccion02CatalogoSeeder::TIPOS)->first(fn (array $tipo): bool => str_starts_with($tipo[1], 'S02_'.strtoupper($literal).'_'))[1];

        return TipoDocumento::query()->where('codigo', $codigo)->where('activo', true)->firstOrFail();
    }

    /** PDF mínimo válido de dos páginas (con relleno opcional para probar el tamaño). */
    protected function pdf(string $nombre = 'documento.pdf', int $relleno = 0): UploadedFile
    {
        $contenido = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R 4 0 R]/Count 2>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\n4 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\n"
            .($relleno ? '%'.str_repeat('x', $relleno)."\n" : '')."trailer<</Root 1 0 R>>\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($nombre, $contenido);
    }

    protected function metadatos(TipoDocumento $tipo, int $inicio, int $fin, array $cambios = []): array
    {
        return [...[
            'seccion_legajo_id' => $tipo->seccion_legajo_id, 'tipo_documento_id' => $tipo->id, 'pdf' => $this->pdf(),
            'folio_inicio' => $inicio, 'folio_fin' => $fin, 'fecha_incorporacion' => today()->toDateString(),
            'emisor' => 'Oficina de Recursos Humanos', 'fecha_emision' => today()->subDays(3)->toDateString(),
            'titular_nombre' => 'Persona Demo', 'titular_documento' => '99000201', 'modalidad_presentacion' => 'copia_simple',
            'relacion_titular' => 'servidor', 'num_resolucion' => $tipo->requiere_num_resolucion ? 'RES-001-2026' : null,
        ], ...$cambios];
    }

    protected function subir(Usuario $persona, array $datos): TestResponse
    {
        return $this->post('/api/v1/expedientes/'.$persona->id.'/documentos', array_filter($datos, fn ($valor) => $valor !== null), ['Accept' => 'application/json']);
    }
}
