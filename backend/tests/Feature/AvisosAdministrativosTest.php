<?php

namespace Tests\Feature;

use App\Models\MovimientoCustodiaLegajo;
use App\Models\NotificacionUsuario;
use App\Models\ProcesoFiscalizacion;
use App\Support\GeneradorAvisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\OperacionesAdministrativas;
use Tests\TestCase;

class AvisosAdministrativosTest extends TestCase
{
    use OperacionesAdministrativas;
    use RefreshDatabase;

    public function test_admin_alerts_are_deduplicated_across_days_and_can_be_marked_read_only_by_recipient(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 12:00:00', 'America/Lima'));
        $admin = $this->prepararOperaciones();
        $legajo = $this->legajoOperativo();
        $doc = $this->documentoOperativo($legajo);
        $doc->update(['fecha_caducidad' => '2026-10-20']);
        ProcesoFiscalizacion::factory()->create(['usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id, 'estado' => 'abierto', 'fecha_limite' => '2026-10-16']);
        MovimientoCustodiaLegajo::factory()->create(['legajo_id' => $legajo->id, 'tipo_movimiento' => 'prestamo', 'fecha_retorno' => null, 'fecha_devolucion_prevista' => '2026-10-16']);
        $this->assertSame(3, GeneradorAvisos::generar());
        $this->assertSame(0, GeneradorAvisos::generar());
        $this->travel(1)->days();
        $this->assertSame(0, GeneradorAvisos::generar());
        $this->getJson('/api/v1/avisos')->assertOk()->assertJsonPath('sin_leer', 3)->assertJsonCount(3, 'data');
        $aviso = NotificacionUsuario::query()->where('usuario_id', $admin->id)->firstOrFail();
        $this->postJson('/api/v1/avisos/'.$aviso->id.'/leer')->assertOk()->assertJsonPath('data.leida', true);
        $lectura = $aviso->refresh()->fecha_lectura;
        $this->postJson('/api/v1/avisos/'.$aviso->id.'/leer')->assertOk();
        $this->assertTrue($lectura->equalTo($aviso->refresh()->fecha_lectura));
        $this->actuarComo();
        $this->postJson('/api/v1/avisos/'.$aviso->id.'/leer')->assertNotFound();
        $this->getJson('/api/v1/avisos')->assertOk()->assertJsonCount(0, 'data');
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson('/api/v1/avisos')->assertForbidden();
        $this->postJson('/api/v1/avisos/'.$aviso->id.'/leer')->assertForbidden();
    }
}
