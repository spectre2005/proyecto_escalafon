<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\Controversia;
use App\Models\DocumentoUsuario;
use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccesoSeccionesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sections_require_admin_and_conflicts_do_not_overwrite_changes(): void
    {
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $this->actuarComo();
        $r = $this->getJson('/api/v1/acceso-secciones')->assertOk()->json();
        $rows = array_map(fn ($s) => array_intersect_key($s, array_flip(['id', 'activa', 'disponibilidad'])), $r['data']);
        $rows[2]['activa'] = false;
        $this->putJson('/api/v1/acceso-secciones', ['secciones' => $rows], ['If-Match' => $r['version']])->assertOk();
        $this->putJson('/api/v1/acceso-secciones', ['secciones' => $rows], ['If-Match' => $r['version']])->assertConflict();
        $u = Usuario::factory()->create();
        $this->getJson('/api/v1/expedientes/'.$u->id.'/seccion-03')->assertForbidden();
        $this->assertFalse(SeccionLegajo::find(3)->activa);
        $this->actuarComo(null, 'asistente_legajo');
        $this->putJson('/api/v1/acceso-secciones', ['secciones' => $rows])->assertForbidden();
    }

    public function test_active_labor_relations_preserve_existing_main_resources(): void
    {
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        $this->actuarComo();
        $usuario = Usuario::factory()->create();
        $controversia = Controversia::factory()->create(['usuario_id' => $usuario->id]);
        $this->getJson('/api/v1/controversias/'.$controversia->id)->assertOk();
        SeccionLegajo::whereKey(9)->update(['activa' => false]);
        $this->getJson('/api/v1/controversias/'.$controversia->id)->assertForbidden();
        SeccionLegajo::whereKey(9)->update(['activa' => true]);
        $this->getJson('/api/v1/controversias/'.$controversia->id)->assertOk();
    }

    public function test_disabling_hides_documents_and_prevents_direct_writes_then_restores_data(): void
    {
        $this->actuarComo();
        $u = Usuario::factory()->create();
        $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => 3]);
        $doc = DocumentoUsuario::factory()->create(['usuario_id' => $u->id, 'seccion_legajo_id' => 3, 'tipo_documento_id' => $tipo->id, 'archivo_id' => ArchivoDocumento::factory()->create()->id]);
        SeccionLegajo::whereIn('id', [2, 3])->update(['activa' => false]);
        $this->getJson('/api/v1/documentos-usuario/'.$doc->id)->assertNotFound();
        $this->getJson('/api/v1/archivos-documento/'.$doc->archivo_id)->assertNotFound();
        $this->postJson('/api/v1/expedientes/'.$u->id.'/vinculos-laborales', [])->assertForbidden();
        $this->postJson('/api/v1/expedientes/'.$u->id.'/documentos', ['seccion_legajo_id' => 3])->assertForbidden();
        SeccionLegajo::whereIn('id', [2, 3])->update(['activa' => true]);
        $this->getJson('/api/v1/documentos-usuario/'.$doc->id)->assertOk();
        $this->assertDatabaseHas('documentos_usuario', ['id' => $doc->id]);
    }
}
