<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\Legajo;
use App\Models\ParametroSistema;
use App\Support\NumeroLegajo;
use App\Support\Parametros;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConfiguracionParametrosTest extends TestCase
{
    use RefreshDatabase;

    private function cambios(int $version = 0): array
    {
        return ['version' => $version, 'motivo' => 'Ajuste institucional para pruebas', 'valores' => [
            'dias_aviso_vencimiento_vinculo' => 15, 'tamano_maximo_pdf_kb' => 1024, 'numero_legajo_prefijo' => 'EXP_{anio}-', 'numero_legajo_digitos' => 4]];
    }

    public function test_values_are_effective_audited_versioned_and_keep_existing_legajo_numbers(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 12:00:00', 'America/Lima'));
        $actor = $this->actuarComo();
        $anterior = Legajo::factory()->create(['numero_legajo' => 'EXP-2026-9999']);
        $this->getJson('/api/v1/configuracion/parametros')->assertOk()->assertJsonPath('data.version', 0)->assertJsonPath('data.valores.tamano_maximo_pdf_kb', 2048);
        $this->putJson('/api/v1/configuracion/parametros', $this->cambios())->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.ejemplo_numero', 'EXP_2026-0001');
        $this->assertSame(1024, Parametros::entero('tamano_maximo_pdf_kb'));
        $this->assertSame('EXP_2026-0001', NumeroLegajo::siguiente());
        $this->assertSame('EXP-2026-9999', $anterior->refresh()->numero_legajo);
        $this->putJson('/api/v1/configuracion/parametros', $this->cambios())->assertConflict();
        $evento = AuditoriaEvento::query()->where('accion', 'parametros.actualizados')->sole();
        $this->assertSame($actor->id, $evento->actor_usuario_id);
        $this->assertSame(2048, json_decode($evento->datos_antes, true)['valores']['tamano_maximo_pdf_kb']);
        $this->assertSame(1024, json_decode($evento->datos_despues, true)['valores']['tamano_maximo_pdf_kb']);
        $parametro = ParametroSistema::query()->where('clave', 'tamano_maximo_pdf_kb')->firstOrFail();
        $this->patchJson('/api/v1/parametros-sistema/'.$parametro->id, ['valor' => '999999'])->assertConflict();
        $this->deleteJson('/api/v1/parametros-sistema/'.$parametro->id)->assertConflict();
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson('/api/v1/configuracion/parametros')->assertForbidden();
        $this->putJson('/api/v1/configuracion/parametros', $this->cambios(1))->assertForbidden();
    }

    public function test_limits_reason_and_reserved_keys_are_validated_before_writing(): void
    {
        $this->actuarComo();
        $antes = ParametroSistema::query()->orderBy('id')->get()->toArray();
        $datos = $this->cambios();
        $this->putJson('/api/v1/configuracion/parametros', [...$datos, 'motivo' => ''])->assertUnprocessable();
        $this->putJson('/api/v1/configuracion/parametros', [...$datos, 'valores' => [...$datos['valores'], 'tamano_maximo_pdf_kb' => 2049]])->assertUnprocessable();
        $this->putJson('/api/v1/configuracion/parametros', [...$datos, 'valores' => [...$datos['valores'], 'numero_legajo_prefijo' => 'ABCDEFGHIJKLMNO{anio}-']])->assertUnprocessable();
        $this->putJson('/api/v1/configuracion/parametros', [...$datos, 'valores' => [...$datos['valores'], 'DOCUMENTOS_CIFRADO_KEY' => 'no-permitido']])->assertUnprocessable();
        $this->assertSame($antes, ParametroSistema::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('auditoria_eventos', 0);
    }
}
