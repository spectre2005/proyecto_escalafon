<?php

namespace Tests\Concerns;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\AlmacenDocumento;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait OperacionesAdministrativas
{
    private function prepararOperaciones(): Usuario
    {
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        Storage::fake((string) config('legajo.disco_documentos'));

        return $this->actuarComo();
    }

    private function fechaOperacion(int $dias = 0): string
    {
        return now('America/Lima')->addDays($dias)->toDateString();
    }

    private function legajoOperativo(): Legajo
    {
        return Legajo::factory()->create(['usuario_id' => Usuario::factory()->create()->id, 'estado_legajo' => 'activo']);
    }

    private function documentoOperativo(Legajo $legajo): DocumentoUsuario
    {
        $contenido = "%PDF-1.4\nDocumento ficticio de prueba\n%%EOF";
        $ruta = 'pruebas/'.Str::uuid().'.pdf';
        $disco = (string) config('legajo.disco_documentos');
        Storage::disk($disco)->put($ruta, AlmacenDocumento::cifrar($contenido));
        $archivo = ArchivoDocumento::factory()->create(['almacenamiento' => $disco, 'storage_path' => $ruta, 'nombre_original' => 'prueba.pdf', 'estado_antivirus' => 'limpio', 'cifrado' => true, 'hash_sha256' => hash('sha256', $contenido), 'tamano_bytes' => strlen($contenido)]);

        return DocumentoUsuario::factory()->create(['usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id, 'archivo_id' => $archivo->id,
            'seccion_legajo_id' => 3, 'tipo_documento_id' => TipoDocumento::factory()->create(['seccion_legajo_id' => 3, 'requiere_fedateo' => false, 'es_dato_sensible' => false, 'es_dato_salud' => false])->id,
            'created_by' => Usuario::factory(), 'estado' => 'cargado', 'fecha_recepcion' => $this->fechaOperacion(), 'recepcionado_por' => Usuario::factory()]);
    }
}
