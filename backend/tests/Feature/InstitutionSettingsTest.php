<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstitutionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_require_a_session_and_get_does_not_create_rows(): void
    {
        $this->getJson('/api/v1/institucion')->assertUnauthorized();
        $this->actuarComo();
        $this->getJson('/api/v1/institucion')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.showLogo', true)->assertJsonPath('configured', false);
        $this->assertDatabaseCount('institution_settings', 0);
    }

    public function test_saved_branding_and_logo_are_shared_with_another_session(): void
    {
        $this->actuarComo();
        $snapshot = $this->getJson('/api/v1/institucion')->assertOk()->json();
        $image = UploadedFile::fake()->image('logo.png', 120, 90);
        $settings = [...$snapshot['data'], 'name' => 'Institución de prueba', 'logo' => 'data:image/png;base64,'.base64_encode($image->get()),
            'ruc' => '12345678901', 'email' => 'contacto@example.test', 'reportColor' => '#123456', 'showEmissionDate' => false];
        $this->putJson('/api/v1/institucion', $settings, ['If-Match' => $snapshot['version']])->assertOk()->assertJsonPath('configured', true);
        $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
        $this->getJson('/api/v1/institucion')->assertOk()->assertJsonPath('data.name', $settings['name'])
            ->assertJsonPath('data.logo', $settings['logo'])->assertJsonPath('data.showEmissionDate', false);
        $this->assertDatabaseCount('institution_settings', 1);
    }

    public function test_only_administration_can_save_settings(): void
    {
        $this->actuarComo(null, 'asistente_legajo');
        $snapshot = $this->getJson('/api/v1/institucion')->assertOk()->json();
        $this->putJson('/api/v1/institucion', $snapshot['data'], ['If-Match' => $snapshot['version']])->assertForbidden();
        $this->assertDatabaseCount('institution_settings', 0);
    }

    public function test_configuracion_modulos_alone_cannot_save_settings(): void
    {
        // Configurar funcionalidades no da acceso a la identidad institucional.
        $this->actuarComo(null, 'configuracion_modulos');
        $snapshot = $this->getJson('/api/v1/institucion')->assertOk()->json();
        $this->putJson('/api/v1/institucion', $snapshot['data'], ['If-Match' => $snapshot['version']])->assertForbidden();
        $this->assertDatabaseCount('institution_settings', 0);
    }

    public function test_stale_configuration_cannot_overwrite_a_confirmed_save(): void
    {
        $this->actuarComo();
        $snapshot = $this->getJson('/api/v1/institucion')->assertOk()->json();
        $this->putJson('/api/v1/institucion', [...$snapshot['data'], 'name' => 'Primer cambio'], ['If-Match' => $snapshot['version']])->assertOk();
        $this->putJson('/api/v1/institucion', [...$snapshot['data'], 'name' => 'Cambio antiguo'], ['If-Match' => $snapshot['version']])->assertConflict();
        $this->getJson('/api/v1/institucion')->assertJsonPath('data.name', 'Primer cambio');
        $this->assertDatabaseCount('institution_settings', 1);
    }

    public function test_invalid_logo_contact_and_unknown_fields_do_not_save(): void
    {
        $this->actuarComo();
        $snapshot = $this->getJson('/api/v1/institucion')->assertOk()->json();
        $largeImage = UploadedFile::fake()->image('large.png', 513, 20);
        foreach ([
            ['logo' => 'data:image/svg+xml;base64,AAAA'],
            ['logo' => 'data:image/png;base64,'.base64_encode('not an image')],
            ['logo' => 'data:image/png;base64,'.base64_encode($largeImage->get())],
            ['ruc' => '123'], ['email' => 'invalid'], ['website' => 'javascript:alert(1)'], ['reportColor' => 'blue'], ['unknown' => 'value'],
        ] as $invalid) {
            $this->putJson('/api/v1/institucion', [...$snapshot['data'], ...$invalid], ['If-Match' => $snapshot['version']])
                ->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
        }
        $this->assertDatabaseCount('institution_settings', 0);
    }

    public function test_logo_can_be_removed_and_unversioned_saves_are_rejected(): void
    {
        $this->actuarComo();
        $snapshot = $this->getJson('/api/v1/institucion')->assertOk()->json();
        $this->putJson('/api/v1/institucion', $snapshot['data'])->assertStatus(428);
        $image = UploadedFile::fake()->image('logo.png', 20, 20);
        $saved = $this->putJson('/api/v1/institucion', [...$snapshot['data'], 'logo' => 'data:image/png;base64,'.base64_encode($image->get())], ['If-Match' => $snapshot['version']])->assertOk()->json();
        $this->putJson('/api/v1/institucion', [...$saved['data'], 'logo' => ''], ['If-Match' => $saved['version']])->assertOk()->assertJsonPath('data.logo', '');
        $this->assertNotNull(DB::table('institution_settings')->value('updated_by'));
    }
}
