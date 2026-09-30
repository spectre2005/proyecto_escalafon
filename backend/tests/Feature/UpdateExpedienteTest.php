<?php

namespace Tests\Feature;

use App\Models\Ubigeo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateExpedienteTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_saves_contact_and_location_and_returns_fresh_general_data(): void
    {
        $person = Usuario::factory()->create();
        $this->patchJson('/api/v1/expedientes/'.$person->id, [
            'nombres' => 'Persona de prueba',
            'telefono' => '999111222',
            'email_institucional' => 'PRUEBA@example.test',
            'ubicacion' => ['region' => 'Region ficticia', 'provincia' => 'Provincia ficticia', 'distrito' => 'Distrito ficticio'],
        ])->assertOk()->assertJsonPath('data.usuario.values.nombres', 'Persona de prueba')
            ->assertJsonPath('data.usuario.values.email_institucional', 'prueba@example.test')
            ->assertJsonPath('data.usuario.values.region', 'REGION FICTICIA')
            ->assertJsonPath('data.usuario.values.provincia', 'PROVINCIA FICTICIA')
            ->assertJsonPath('data.usuario.values.distrito', 'DISTRITO FICTICIO')
            ->assertJsonMissingPath('data.usuario.values.ubigeo_id')
            ->assertJsonMissingPath('data.usuario.values.password');
        $this->assertDatabaseHas('usuarios', ['id' => $person->id, 'nombres' => 'Persona de prueba']);
        $this->assertDatabaseHas('ubigeo', ['id' => $person->refresh()->ubigeo_id, 'distrito' => 'DISTRITO FICTICIO']);
    }

    public function test_reuses_location_case_insensitively_and_does_not_change_other_people(): void
    {
        $location = Ubigeo::factory()->create(['region' => 'Region de prueba', 'provincia' => 'Provincia de prueba', 'distrito' => 'Distrito de prueba']);
        $other = Usuario::factory()->create(['ubigeo_id' => $location->id]);
        $person = Usuario::factory()->create();
        $uri = '/api/v1/expedientes/'.$person->id;
        $this->patchJson($uri, ['ubicacion' => ['region' => 'REGION DE PRUEBA', 'provincia' => 'PROVINCIA DE PRUEBA', 'distrito' => 'DISTRITO DE PRUEBA']])->assertOk();
        $this->assertSame($location->id, $person->refresh()->ubigeo_id);
        $this->assertDatabaseCount('ubigeo', 1);
        $this->patchJson($uri, ['ubicacion' => ['region' => 'Otra region', 'provincia' => 'Otra provincia', 'distrito' => 'Otro distrito']])->assertOk();
        $this->assertNotSame($location->id, $person->refresh()->ubigeo_id);
        $this->assertSame($location->id, $other->refresh()->ubigeo_id);
        $this->assertSame('Distrito de prueba', $location->refresh()->distrito);
        $this->patchJson($uri, ['ubicacion' => null])->assertOk()->assertJsonPath('data.usuario.values.region', null);
        $this->assertNull($person->refresh()->ubigeo_id);
    }

    public function test_location_options_follow_region_and_province(): void
    {
        Ubigeo::factory()->create(['region' => 'A', 'provincia' => 'B', 'distrito' => 'C']);
        Ubigeo::factory()->create(['region' => 'A', 'provincia' => 'D', 'distrito' => 'E']);
        Ubigeo::factory()->create(['region' => 'X', 'provincia' => 'Y', 'distrito' => 'Z']);
        $this->getJson('/api/v1/ubicaciones')->assertOk()->assertExactJson(['data' => ['A', 'X']]);
        $this->getJson('/api/v1/ubicaciones?region=A')->assertOk()->assertExactJson(['data' => ['B', 'D']]);
        $this->getJson('/api/v1/ubicaciones?region=A&provincia=B')->assertOk()->assertExactJson(['data' => ['C']]);
    }

    public function test_invalid_and_privileged_fields_are_rejected_without_partial_writes(): void
    {
        $person = Usuario::factory()->create();
        $uri = '/api/v1/expedientes/'.$person->id;
        foreach ([['password' => 'secret'], ['activo' => true], ['tipo_personal' => 'docente'], ['ubicacion' => []], ['ubicacion' => ['region' => 'X']], ['fecha_nacimiento' => '2999-01-01']] as $invalid) {
            $this->patchJson($uri, ['nombres' => 'No guardar', ...$invalid])->assertUnprocessable();
        }
        $this->assertNotSame('No guardar', $person->refresh()->nombres);
        $this->assertDatabaseCount('ubigeo', 0);
    }

    public function test_duplicate_email_is_rejected_before_creating_location(): void
    {
        Usuario::factory()->create(['email_institucional' => 'existente@example.test']);
        $person = Usuario::factory()->create();
        $this->patchJson('/api/v1/expedientes/'.$person->id, [
            'email_institucional' => 'EXISTENTE@example.test',
            'ubicacion' => ['region' => 'X', 'provincia' => 'Y', 'distrito' => 'Z'],
        ])->assertUnprocessable()->assertJsonValidationErrors('email_institucional');
        $this->assertDatabaseCount('ubigeo', 0);
    }

    public function test_stale_edit_cannot_overwrite_a_more_recent_update(): void
    {
        $person = Usuario::factory()->create();
        $uri = '/api/v1/expedientes/'.$person->id;
        $version = $this->getJson($uri)->json('data.version');
        $this->withHeader('If-Match', $version)->patchJson($uri, ['nombres' => 'Actualizado'])->assertOk();
        $this->withHeader('If-Match', $version)->patchJson($uri, ['nombres' => 'Desactualizado'])->assertConflict();
        $this->assertSame('Actualizado', $person->refresh()->nombres);
    }

    public function test_edit_and_location_catalog_remain_disabled_in_production(): void
    {
        $person = Usuario::factory()->create();
        $this->app->instance('env', 'production');
        $this->patchJson('/api/v1/expedientes/'.$person->id, ['nombres' => 'No guardar'])->assertForbidden();
        $this->getJson('/api/v1/ubicaciones')->assertForbidden();
    }
}
