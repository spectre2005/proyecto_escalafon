<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\AuditoriaPanelController;
use App\Models\AuditoriaEvento;
use App\Models\BitacoraAccesoLegajo;
use App\Models\Plataforma\AuditoriaPlataforma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

class AuditoriaPanelTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    public function test_filters_use_lima_dates_redact_credentials_and_export_all_results_safely(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 02:00:00', 'UTC'));
        $actor = $this->actuarComo();
        $actor->update(['nombres' => '=PRUEBA']);
        foreach (['2026-10-17 23:59:00', '2026-10-18 04:59:59', '2026-10-18 05:00:00'] as $fecha) {
            AuditoriaEvento::factory()->create(['fecha_evento' => $fecha, 'actor_usuario_id' => $actor->id, 'accion' => 'legajo.creado',
                'datos_despues' => json_encode(['password' => 'no-divulgar', 'anidado' => ['token' => 'token-privado'], 'estado' => 'activo'])]);
        }
        $url = '/api/v1/auditoria?desde=2026-10-17&hasta=2026-10-17&accion=legajo&actor=PRUEBA';
        $respuesta = $this->getJson($url)->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.despues.password', '[Omitido]');
        $this->assertStringNotContainsString('no-divulgar', $respuesta->getContent());
        $this->assertStringNotContainsString('token-privado', $respuesta->getContent());
        $solicitud = Request::create('/api/v1/auditoria/exportar?desde=2026-10-17&hasta=2026-10-17&accion=legajo&actor=PRUEBA');
        $csv = app(AuditoriaPanelController::class)->exportar($solicitud);
        $contenido = file_get_contents($csv->getFile()->getPathname());
        $this->get('/api/v1/auditoria/exportar?desde=2026-10-17&hasta=2026-10-17&accion=legajo&actor=PRUEBA')->assertOk()->assertDownload('auditoria-institucional.csv');
        $this->assertSame(2, substr_count($contenido, 'legajo.creado'));
        $this->assertStringContainsString("'=PRUEBA", $contenido);
        $this->assertStringNotContainsString('no-divulgar', $contenido);
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson($url)->assertForbidden();
        $this->get('/api/v1/auditoria/exportar')->assertForbidden();
    }

    public function test_access_register_and_global_audit_have_separate_authorization(): void
    {
        $this->actuarComo();
        BitacoraAccesoLegajo::factory()->create(['fecha_acceso' => now(), 'accion' => 'descargar']);
        $this->getJson('/api/v1/auditoria/accesos?accion=descargar')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/plataforma/auditoria')->assertUnauthorized();
        $this->actingAs($this->administrador(), 'plataforma');
        $institucion = $this->institucion();
        AuditoriaPlataforma::query()->create(['accion' => 'institucion.archivada', 'institucion_id' => $institucion->id, 'datos_despues' => ['password' => 'secreto', 'estado' => 'archivada']]);
        $this->getJson('/api/plataforma/auditoria?institucion_id='.$institucion->id)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.despues.password', '[Omitido]');
        $this->get('/api/plataforma/auditoria/exportar?institucion_id='.$institucion->id)->assertOk();
    }
}
