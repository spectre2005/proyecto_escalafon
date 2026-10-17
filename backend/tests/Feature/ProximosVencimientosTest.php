<?php

namespace Tests\Feature;

use App\Models\CicloVigencia;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Models\VinculoAdenda;
use App\Models\VinculoLaboral;
use Carbon\Carbon;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProximosVencimientosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-17 23:30:00', 'America/Lima'));
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
    }

    public function test_three_sources_are_sorted_together_with_inclusive_boundaries(): void
    {
        $this->actuarComo();
        $legajo = Legajo::factory()->create(['estado_legajo' => 'activo']);
        $this->documento($legajo, 0);
        $this->documento($legajo, 6);
        $this->documento($legajo, -1);
        $this->documento($legajo, 31);
        $this->vinculo($legajo, 7);
        CicloVigencia::factory()->create(['legajo_id' => $legajo->id, 'fecha_vencimiento' => '2026-11-16']);
        $respuesta = $this->getJson('/api/v1/vencimientos/proximos')->assertOk()->assertJsonPath('total', 4)
            ->assertJsonPath('hoy', '2026-10-17')->assertJsonPath('dias', 30);
        $this->assertSame([0, 6, 7, 30], array_column($respuesta->json('data'), 'dias_restantes'));
        $this->assertSame(['rojo', 'rojo', 'amarillo', 'amarillo'], array_column($respuesta->json('data'), 'criticidad'));
        $this->assertSame(['documento', 'documento', 'vinculo', 'ciclo'], array_column($respuesta->json('data'), 'origen'));
    }

    public function test_latest_non_annulled_adenda_determines_contract_expiry(): void
    {
        $this->actuarComo();
        $legajo = Legajo::factory()->create(['estado_legajo' => 'activo']);
        $vinculo = $this->vinculo($legajo, 2);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-10-25', 'anulado' => false]);
        VinculoAdenda::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2027-01-01', 'anulado' => true]);
        $this->getJson('/api/v1/vencimientos/proximos?origen=vinculo')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.fecha', '2026-10-25');
    }

    public function test_range_area_and_personnel_filters_are_applied_before_pagination(): void
    {
        $this->actuarComo();
        $area = UnidadOrganica::factory()->create();
        $persona = Usuario::factory()->create(['tipo_personal' => 'administrativo', 'unidad_organica_id' => $area->id]);
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'activo']);
        $this->documento($legajo, 40);
        $this->documento(Legajo::factory()->create(['estado_legajo' => 'activo']), 2);
        $this->getJson('/api/v1/vencimientos/proximos?dias=45&tipo_personal=administrativo&unidad_id='.$area->id)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.area', $area->nombre)->assertJsonPath('data.0.dias_restantes', 40);
    }

    public function test_inactive_legajos_annulled_documents_and_ended_contracts_are_excluded(): void
    {
        $this->actuarComo();
        $cerrado = Legajo::factory()->create(['estado_legajo' => 'pasivo']);
        $this->documento($cerrado, 2);
        $activo = Legajo::factory()->create(['estado_legajo' => 'activo']);
        $this->documento($activo, 2)->update(['estado' => 'anulado']);
        $this->vinculo($activo, 2)->update(['fecha_cese' => '2026-10-16']);
        $this->vinculo($activo, 3)->update(['anulado' => true]);
        $this->getJson('/api/v1/vencimientos/proximos')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_disabled_sections_and_sensitive_documents_do_not_leak_to_assistants(): void
    {
        $legajo = Legajo::factory()->create(['estado_legajo' => 'activo']);
        $this->documento($legajo, 2, 10);
        $this->documento($legajo, 3, 3);
        $this->actuarComo(null, 'asistente_legajo');
        $this->getJson('/api/v1/vencimientos/proximos')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.dias_restantes', 3);
        SeccionLegajo::whereKey(3)->update(['activa' => false]);
        $this->getJson('/api/v1/vencimientos/proximos')->assertOk()->assertJsonPath('total', 0);
        $this->actuarComo();
        $this->getJson('/api/v1/vencimientos/proximos')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.dias_restantes', 2);
    }

    public function test_personal_can_only_list_their_own_expirations(): void
    {
        $persona = $this->actuarComo(null, 'personal');
        $propio = Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'activo']);
        $this->documento($propio, 1, 10);
        $this->documento(Legajo::factory()->create(['estado_legajo' => 'activo']), 2);
        $this->getJson('/api/v1/vencimientos/proximos')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.usuario_id', $persona->id)->assertJsonCount(0, 'areas');
    }

    public function test_pagination_is_global_across_sources_and_date_order(): void
    {
        $this->actuarComo();
        $legajo = Legajo::factory()->create(['estado_legajo' => 'activo']);
        foreach (range(1, 21) as $dias) {
            $this->documento($legajo, $dias);
        }
        $this->vinculo($legajo, 0);
        $this->getJson('/api/v1/vencimientos/proximos')->assertOk()->assertJsonPath('total', 22)->assertJsonCount(20, 'data')->assertJsonPath('data.0.origen', 'vinculo');
        $this->getJson('/api/v1/vencimientos/proximos?page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.dias_restantes', 20);
    }

    public function test_invalid_filters_are_rejected_and_authentication_is_required(): void
    {
        $this->getJson('/api/v1/vencimientos/proximos')->assertUnauthorized();
        $this->actuarComo();
        foreach (['dias=0', 'dias=366', 'dias=abc', 'origen=otro', 'unidad_id=invalid', 'page=0'] as $filtro) {
            $this->getJson('/api/v1/vencimientos/proximos?'.$filtro)->assertUnprocessable();
        }
    }

    private function documento(Legajo $legajo, int $dias, int $seccion = 3): DocumentoUsuario
    {
        $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => $seccion, 'es_dato_sensible' => false, 'es_dato_salud' => false]);

        return DocumentoUsuario::factory()->create(['legajo_id' => $legajo->id, 'usuario_id' => $legajo->usuario_id,
            'tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => $seccion, 'fecha_caducidad' => now('America/Lima')->addDays($dias)->toDateString(), 'estado' => 'pendiente']);
    }

    private function vinculo(Legajo $legajo, int $dias): VinculoLaboral
    {
        return VinculoLaboral::factory()->create(['usuario_id' => $legajo->usuario_id, 'fecha_inicio' => '2026-01-01',
            'fecha_fin_prevista' => now('America/Lima')->addDays($dias)->toDateString(), 'anulado' => false]);
    }
}
