<?php

namespace Tests\Feature;

use App\Models\Legajo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Una ficha al cierre que no se puede descifrar (p. ej. tras cambiar APP_KEY) no rompe el listado de pasivos. */
class FichaAlCierreIlegibleTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_ficha_ilegible_se_trata_como_sin_ficha(): void
    {
        $this->actuarComo();
        $legajo = Legajo::factory()->create(['estado_legajo' => 'pasivo', 'fecha_pasivo' => '2026-01-10']);
        DB::table('legajos')->where('id', $legajo->id)->update(['datos_al_cierre' => 'no-es-un-cifrado-valido']);

        $this->assertNull($legajo->refresh()->datosHistoricos(true));
        $this->getJson('/api/v1/expedientes/legajos-pasivos')->assertOk()->assertJsonPath('data.0.id', $legajo->id)
            ->assertJsonPath('data.0.persona.numero_doc_identidad', $legajo->usuario->numero_doc_identidad);
    }
}
