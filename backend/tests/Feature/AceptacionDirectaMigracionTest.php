<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\ObservacionDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AceptacionDirectaMigracionTest extends TestCase
{
    use RefreshDatabase;

    private function documento(int $seccion, string $estado = 'pendiente'): DocumentoUsuario
    {
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $autor = Usuario::factory()->create();
        $legajo = Legajo::factory()->create(['usuario_id' => $autor->id, 'estado_legajo' => 'activo']);
        $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => $seccion]);
        $archivo = ArchivoDocumento::factory()->create(['subido_por' => $autor->id, 'mime_type' => 'application/pdf', 'estado_antivirus' => 'pendiente']);

        return DocumentoUsuario::factory()->create([
            'usuario_id' => $autor->id, 'legajo_id' => $legajo->id, 'tipo_documento_id' => $tipo->id,
            'seccion_legajo_id' => $seccion, 'archivo_id' => $archivo->id, 'created_by' => $autor->id,
            'estado' => $estado, 'folio_inicio' => 1, 'folio_fin' => 3,
        ]);
    }

    private function regularizar(): void
    {
        $migracion = require database_path('migrations/2026_10_19_164657_aceptar_documentos_anteriores_por_registro_directo.php');
        $migracion->up();
    }

    public static function secciones(): array
    {
        $casos = [];
        foreach (range(1, 11) as $seccion) {
            foreach (['pendiente', 'cargado'] as $estado) {
                $casos["{$seccion}-{$estado}"] = [$seccion, $estado];
            }
        }

        return $casos;
    }

    #[DataProvider('secciones')]
    public function test_regularizes_previous_uploads_in_every_section_once_with_history(int $seccion, string $estado): void
    {
        $doc = $this->documento($seccion, $estado);
        $this->regularizar();
        $this->regularizar();
        $this->assertSame('aceptado', $doc->fresh()->estado);
        $this->assertSame(3, $doc->fresh()->folio_fin);
        $this->assertFalse($doc->fresh()->fiscalizado);
        $this->assertNull($doc->fresh()->fecha_recepcion);
        $this->assertDatabaseCount('historial_estados_documento', 1);
        $this->assertDatabaseHas('historial_estados_documento', [
            'documento_usuario_id' => $doc->id, 'estado_anterior' => $estado,
            'estado_nuevo' => 'aceptado', 'cambiado_por' => $doc->created_by,
        ]);
    }

    public function test_missing_infected_observed_annulled_and_superseded_documents_are_preserved(): void
    {
        $sinArchivo = $this->documento(10);
        $sinArchivo->update(['archivo_id' => null]);
        $infectado = $this->documento(4);
        $infectado->archivo->update(['estado_antivirus' => 'infectado']);
        $observado = $this->documento(3, 'observado');
        $anulado = $this->documento(6, 'anulado');
        $conObservacion = $this->documento(8);
        ObservacionDocumento::factory()->create(['documento_id' => $conObservacion->id, 'estado' => 'abierta']);
        $anterior = $this->documento(2);
        DocumentoUsuario::factory()->create(['subsana_a_documento_id' => $anterior->id, 'estado' => 'aceptado']);
        $cerrado = $this->documento(1);
        $cerrado->legajo->update(['estado_legajo' => 'pasivo']);
        $this->regularizar();
        foreach ([$sinArchivo, $infectado, $observado, $anulado, $conObservacion, $anterior, $cerrado] as $doc) {
            $this->assertSame($doc->estado, $doc->fresh()->estado);
        }
        $this->assertDatabaseCount('historial_estados_documento', 0);
    }
}
