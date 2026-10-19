<?php

namespace Tests\Concerns;

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
    protected function pdf(string $nombre = 'documento.pdf', int $relleno = 0, int $paginas = 2): UploadedFile
    {
        $contenido = self::pdfValido($paginas, $relleno);

        return UploadedFile::fake()->createWithContent($nombre, $contenido);
    }

    protected function metadatos(TipoDocumento $tipo, int $inicio, int $fin, array $cambios = []): array
    {
        return [...[
            'seccion_legajo_id' => $tipo->seccion_legajo_id, 'tipo_documento_id' => $tipo->id,
            'pdf' => UploadedFile::fake()->createWithContent('documento.pdf', self::pdfValido(max(1, $fin - $inicio + 1))),
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
