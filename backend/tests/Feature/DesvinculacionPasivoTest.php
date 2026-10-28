<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\RegimenNormativo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Database\Seeders\Seccion11CatalogoSeeder;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DesvinculacionPasivoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $persona;

    private Legajo $legajo;

    private VinculoLaboral $vinculo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        $this->actuarComo();
        $this->seed([SeccionesLegajoCatalogoSeeder::class, Seccion11CatalogoSeeder::class]);
        $this->persona = Usuario::factory()->create();
        $this->legajo = Legajo::factory()->create(['usuario_id' => $this->persona->id, 'fecha_apertura' => '2026-01-01']);
        $this->vinculo = VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'fecha_inicio' => '2026-01-01', 'fecha_cese' => '2026-10-01']);
    }

    private function url(): string
    {
        return '/api/v1/expedientes/'.$this->persona->id;
    }

    private function registrar(?VinculoLaboral $vinculo = null): TestResponse
    {
        $vinculo ??= $this->vinculo;

        return $this->postJson($this->url().'/seccion-11/desvinculaciones', [
            'vinculo_laboral_id' => $vinculo->id, 'motivo_cese' => 'otro', 'fecha_cese' => $vinculo->fecha_cese->toDateString(),
        ]);
    }

    public function test_cierra_el_legajo_tras_el_ultimo_cese_y_conserva_su_historial(): void
    {
        $this->registrar()->assertCreated();
        $this->assertSame('pasivo', $this->legajo->fresh()->estado_legajo);
        $this->assertSame('2026-10-28', $this->legajo->fresh()->fecha_pasivo->toDateString());
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $this->legajo->id, 'accion' => 'legajo.cerrar_por_desvinculacion']);
        $this->patchJson($this->url(), ['nombres' => 'Cambio'])->assertConflict();
        $this->getJson('/api/v1/expedientes/legajos-pasivos/resumen')->assertOk()->assertJsonPath('data.total', 1);
        $this->assertDatabaseCount('desvinculaciones', 1);
    }

    public function test_otro_vinculo_sin_cese_impide_el_pase_a_pasivo_hasta_terminar_el_ultimo(): void
    {
        $otro = VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'fecha_inicio' => '2026-02-01', 'fecha_cese' => null]);
        $this->registrar()->assertCreated();
        $this->assertSame('activo', $this->legajo->fresh()->estado_legajo);
        $otro->update(['fecha_cese' => '2026-10-27']);
        $this->registrar($otro)->assertCreated();
        $this->assertSame('pasivo', $this->legajo->fresh()->estado_legajo);
    }

    public function test_no_cierra_por_un_cese_futuro(): void
    {
        $this->vinculo->update(['fecha_cese' => '2026-11-01']);
        $this->registrar()->assertUnprocessable()->assertJsonValidationErrors('fecha_cese');
        $this->assertSame('activo', $this->legajo->fresh()->estado_legajo);
        $this->assertDatabaseCount('desvinculaciones', 0);
    }

    public function test_reingreso_abre_otro_legajo_y_vinculo_sin_duplicar_la_persona_ni_mezclar_documentos(): void
    {
        $tipo = TipoDocumento::query()->where('codigo', 'S11_C_TERMINO_VINCULO')->firstOrFail();
        $documento = DocumentoUsuario::factory()->create(['usuario_id' => $this->persona->id, 'legajo_id' => $this->legajo->id, 'seccion_legajo_id' => 11, 'tipo_documento_id' => $tipo->id]);
        $personas = Usuario::query()->count();
        $this->registrar()->assertCreated();
        $nuevo = $this->postJson($this->url().'/legajo')->assertCreated()->json('data.id');
        $this->assertNotSame($this->legajo->id, $nuevo);
        $this->postJson($this->url().'/legajos/'.$this->legajo->id.'/reabrir')->assertConflict();
        $this->assertDatabaseCount('usuarios', $personas);
        $this->assertSame('pasivo', $this->legajo->fresh()->estado_legajo);
        $this->assertSame($this->legajo->id, $documento->fresh()->legajo_id);
        $this->assertSame(0, DocumentoUsuario::query()->where('legajo_id', $nuevo)->count());
        $this->assertSame(0, (int) Legajo::query()->findOrFail($nuevo)->legajoSecciones()->max('ultimo_folio'));
        $ley = RegimenNormativo::factory()->create(['aplica_a' => 'administrativo']);
        $this->postJson($this->url().'/vinculos-laborales', [
            'tipo_personal' => 'administrativo', 'regimen_laboral' => 'plazo_determinado', 'regimen_normativo_id' => $ley->id,
            'nombre_puesto' => 'Analista', 'fecha_inicio' => '2026-10-28', 'numero_documento_vinculo' => 'CONTRATO-002-2026',
        ])->assertCreated();
        $this->assertDatabaseCount('vinculos_laborales', 2);
        $this->getJson('/api/v1/expedientes/legajos-pasivos?q='.$this->persona->numero_doc_identidad)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $this->legajo->id);
        $this->getJson($this->url().'/documentos-usuario?legajo_id='.$this->legajo->id)->assertOk()->assertJsonPath('total', 1);
        $this->getJson($this->url().'/documentos-usuario?legajo_id='.$nuevo)->assertOk()->assertJsonPath('total', 0);
    }

    public function test_listado_cuenta_legajos_y_no_personas_y_aplica_permisos(): void
    {
        $this->registrar()->assertCreated();
        Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'pasivo', 'fecha_pasivo' => '2025-01-01']);
        Legajo::factory()->create();
        $this->getJson('/api/v1/expedientes/legajos-pasivos/resumen')->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson('/api/v1/expedientes/legajos-pasivos?q='.$this->persona->numero_doc_identidad)->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/expedientes/legajos-pasivos?q=INEXISTENTE')->assertOk()->assertJsonPath('total', 0);
        $this->actuarComo(codigo: 'rol_sin_permisos');
        $this->getJson('/api/v1/expedientes/legajos-pasivos')->assertForbidden();
        $this->getJson('/api/v1/expedientes/legajos-pasivos/resumen')->assertForbidden();
    }
}
