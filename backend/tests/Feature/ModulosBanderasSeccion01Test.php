<?php

namespace Tests\Feature;

use App\Models\CertificadoDiscapacidad;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Database\Seeders\Seccion01CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulos de la sección 01 que sigue una bandera de la persona (AplicacionDocumental::MODULOS): «Familia y
 * derechohabientes» (tiene_derechohabientes) y «Discapacidad» (con_discapacidad). Con No y sin datos registrados
 * (registros no anulados ni documentos de sus tipos) se ocultan; con datos, Sí o Sin responder, se mantienen.
 */
class ModulosBanderasSeccion01Test extends TestCase
{
    use RefreshDatabase;

    private Usuario $persona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Seccion01CatalogoSeeder::class);
        $this->persona = Usuario::factory()->create(['tiene_derechohabientes' => false, 'con_discapacidad' => false]);
        Legajo::factory()->create(['usuario_id' => $this->persona->id, 'estado_legajo' => 'activo']);
    }

    private function ocultos(): array
    {
        return $this->getJson('/api/v1/expedientes/'.$this->persona->id)->assertOk()->json('data.condiciones.modulos_ocultos');
    }

    private function documento(string $codigo, string $estado = 'cargado'): void
    {
        DocumentoUsuario::factory()->create(['usuario_id' => $this->persona->id, 'legajo_id' => $this->persona->legajos()->value('id'), 'seccion_legajo_id' => 1,
            'tipo_documento_id' => TipoDocumento::query()->where('codigo', $codigo)->value('id'), 'estado' => $estado, 'folio_inicio' => 1, 'folio_fin' => 1]);
    }

    public function test_modules_hide_with_no_and_without_data_and_stay_with_yes_or_unanswered(): void
    {
        $this->actuarComo();
        $this->assertSame(['s01-familia', 's01-discapacidad'], $this->ocultos());
        $this->persona->update(['tiene_derechohabientes' => true, 'con_discapacidad' => null]);
        $this->assertSame([], $this->ocultos());
    }

    public function test_registered_family_members_keep_the_family_module_but_annulled_ones_do_not(): void
    {
        $this->actuarComo();
        $familiar = Familiar::factory()->create(['usuario_id' => $this->persona->id, 'parentesco' => 'hijo', 'anulado' => false]);
        $this->assertSame(['s01-discapacidad'], $this->ocultos());
        $familiar->update(['anulado' => true, 'motivo_anulacion' => 'Duplicado']);
        $this->assertSame(['s01-familia', 's01-discapacidad'], $this->ocultos());
    }

    public function test_registered_certificates_keep_the_disability_module_but_annulled_ones_do_not(): void
    {
        $this->actuarComo();
        $certificado = CertificadoDiscapacidad::factory()->create(['usuario_id' => $this->persona->id, 'persona' => 'servidor', 'anulado' => false]);
        $this->assertSame(['s01-familia'], $this->ocultos());
        $certificado->update(['anulado' => true, 'motivo_anulacion' => 'Duplicado']);
        $this->assertSame(['s01-familia', 's01-discapacidad'], $this->ocultos());
    }

    public function test_loaded_documents_keep_their_module_in_any_state(): void
    {
        $this->actuarComo();
        $this->documento('S01_I_DNI_CONYUGE_CONCUBINO', 'observado');
        $this->assertSame(['s01-discapacidad'], $this->ocultos());
        $this->documento('S01_K_CERTIFICADO_DISCAPACIDAD', 'anulado');
        $this->assertSame([], $this->ocultos());
    }

    public function test_saving_the_flag_returns_the_updated_modules(): void
    {
        $this->actuarComo();
        $this->patchJson('/api/v1/expedientes/'.$this->persona->id, ['con_discapacidad' => true])->assertOk()
            ->assertJsonPath('data.condiciones.modulos_ocultos', ['s01-familia']);
        $this->patchJson('/api/v1/expedientes/'.$this->persona->id, ['tiene_derechohabientes' => null])->assertOk()
            ->assertJsonPath('data.condiciones.modulos_ocultos', []);
    }

    public function test_hidden_modules_reject_new_records_that_contradict_their_flag(): void
    {
        $this->actuarComo();
        $this->assertSame(['s01-familia', 's01-discapacidad'], $this->ocultos());
        $url = '/api/v1/expedientes/'.$this->persona->id;
        // Con la bandera en No, los registros que la contradicen se rechazan (CondicionesRegistro).
        $this->postJson($url.'/familiares', ['apellidos_nombres' => 'Hija Prueba', 'parentesco' => 'hijo', 'es_derechohabiente' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('es_derechohabiente');
        $this->postJson($url.'/certificados-discapacidad', ['persona' => 'servidor', 'numero_conadis' => 'C-123'])->assertUnprocessable()->assertJsonValidationErrors('persona');
        $this->assertSame(['s01-familia', 's01-discapacidad'], $this->ocultos());
        // Un familiar que no es derechohabiente no contradice la bandera; al registrarlo, Familia vuelve a verse.
        $this->postJson($url.'/familiares', ['apellidos_nombres' => 'Padre Prueba', 'parentesco' => 'otro', 'es_derechohabiente' => false])->assertCreated();
        $this->assertSame(['s01-discapacidad'], $this->ocultos());
    }

    public function test_without_sensitive_data_no_module_is_hidden_and_no_person_flag_is_sent(): void
    {
        $this->actuarComo(null, 'asistente_legajo');
        $respuesta = $this->getJson('/api/v1/expedientes/'.$this->persona->id)->assertOk();
        $this->assertSame([], $respuesta->json('data.condiciones.modulos_ocultos'));
        // Ni en la ficha ni en las condiciones: ocultar o mostrar el módulo revelaría la bandera.
        $this->assertStringNotContainsString('con_discapacidad', $respuesta->getContent());
        $this->assertStringNotContainsString('tiene_derechohabientes', $respuesta->getContent());
    }
}
