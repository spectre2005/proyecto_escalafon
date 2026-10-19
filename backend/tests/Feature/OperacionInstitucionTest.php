<?php

namespace Tests\Feature;

use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use App\Models\Plataforma\PeriodoServicio;
use App\Models\Usuario;
use App\Support\Plataforma\ConexionInstitucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

class OperacionInstitucionTest extends TestCase
{
    use RefreshDatabase, UsaPlataforma;

    public function test_contract_is_platform_only_versioned_and_separate_from_branding(): void
    {
        $this->actingAs($this->administrador(), 'plataforma');
        $i = $this->institucion();
        $datos = ['razon_social' => 'Entidad pública de prueba', 'ruc' => '20123456789', 'contacto_nombre' => 'Contacto autorizado', 'contacto_email' => 'contacto@example.test', 'contacto_telefono' => '999888777', 'numero_contrato' => 'CTR-2026', 'fecha_inicio_contrato' => '2026-01-01', 'version_contrato' => 0, 'motivo' => 'Registrar los datos contractuales'];
        $url = '/api/plataforma/instituciones/'.$i->id.'/contrato';
        $this->putJson($url, [...$datos, 'ruc' => '123'])->assertUnprocessable();
        $this->putJson($url, $datos)->assertOk()->assertJsonPath('data.version_contrato', 1)->assertJsonPath('data.fecha_inicio_contrato', '2026-01-01');
        $this->putJson($url, $datos)->assertConflict();
        $this->assertSame('Entidad de prueba', $i->refresh()->nombre);
        $this->assertSame('institucion.contrato_actualizado', AuditoriaPlataforma::query()->sole()->accion);
        $this->getJson($url)->assertOk()->assertJsonPath('data.ruc', '20123456789');
    }

    public function test_service_overview_uses_effective_coverage_and_excludes_cancelled_and_future_registration_amounts(): void
    {
        $this->travelTo(now('America/Lima')->setDate(2026, 10, 17)->setTime(12, 0));
        $a = $this->administrador();
        $this->actingAs($a, 'plataforma');
        $proximo = $this->institucion(['estado' => Institucion::ACTIVA]);
        $gracia = $this->institucion(['estado' => Institucion::ACTIVA, 'dias_gracia' => 5]);
        $vencido = $this->institucion(['estado' => Institucion::ACTIVA]);
        foreach ([[$proximo, '2026-10-30', '10.25', false], [$gracia, '2026-10-15', '20.50', false], [$vencido, '2026-10-01', '999.00', true]] as [$i, $hasta, $monto, $anulado]) {
            PeriodoServicio::query()->create(['institucion_id' => $i->id, 'desde' => '2026-10-01', 'hasta' => $hasta, 'monto' => $monto, 'registrado_por' => $a->id, 'anulado' => $anulado]);
        }
        $this->getJson('/api/plataforma/dashboard')->assertOk()->assertJsonCount(1, 'data.servicios.proximos')->assertJsonCount(1, 'data.servicios.en_gracia')->assertJsonCount(1, 'data.servicios.vencidos')
            ->assertJsonPath('data.importes_registrados.moneda', 'PEN')->assertJsonPath('data.importes_registrados.mes', '30.75');
    }

    public function test_usage_is_aggregate_only_and_does_not_claim_verified_backups(): void
    {
        $this->actingAs($this->administrador(), 'plataforma');
        $base = storage_path('framework/tenant-consumo.sqlite');
        File::put($base, '');
        $i = $this->institucion(['estado' => Institucion::SUSPENDIDA, 'aprovisionada_en' => now(), 'base_datos' => $base]);
        try {
            ConexionInstitucion::usar($i, function (): void {
                Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
                Usuario::factory()->create(['nombres' => 'Nombre que no debe exponerse', 'ultimo_acceso' => now()]);
                Storage::disk(config('legajo.disco_documentos'))->put('consumo.pdf', 'ejemplo de bytes');
            });
            $r = $this->getJson('/api/plataforma/instituciones/'.$i->id.'/consumo')->assertOk()->assertJsonPath('data.personas', 1)->assertJsonPath('data.documentos', 0)->assertJsonPath('data.respaldo', 'sin_verificacion')->assertJsonPath('data.bytes_archivos', strlen('ejemplo de bytes'));
            $this->assertStringNotContainsString('Nombre que no debe exponerse', $r->getContent());
            $this->assertStringNotContainsString($base, $r->getContent());
        } finally {
            File::delete($base);
        }
    }
}
