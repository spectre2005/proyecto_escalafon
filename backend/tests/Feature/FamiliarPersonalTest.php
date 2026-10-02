<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAdministrator;
use App\Models\CertificadoDiscapacidad;
use App\Models\Familiar;
use App\Models\Usuario;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamiliarPersonalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Authenticate::class,
            RequireAdministrator::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_family_can_be_created_updated_listed_and_deleted_for_its_owner(): void
    {
        $person = Usuario::factory()->create();
        $registrar = Usuario::factory()->create();
        $this->actingAs($registrar);
        $url = '/api/v1/expedientes/'.$person->id.'/familiares';
        $id = $this->postJson($url, [
            'apellidos_nombres' => 'Familiar ficticio', 'parentesco' => 'Hija',
            'tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => '00112233',
            'fecha_nacimiento' => '2010-01-01', 'es_derechohabiente' => true,
        ])->assertCreated()->assertJsonPath('data.usuario_id', $person->id)->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.values.apellidos_nombres', 'Familiar ficticio');
        $this->patchJson($url.'/'.$id, ['apellidos_nombres' => 'Familiar actualizado', 'es_derechohabiente' => false, 'fecha_nacimiento' => null])
            ->assertOk()->assertJsonPath('data.es_derechohabiente', false)->assertJsonPath('data.fecha_nacimiento', null);
        $this->assertDatabaseHas('familiares', ['id' => $id, 'registrado_por' => $registrar->id, 'numero_doc_identidad' => '00112233']);
        $this->deleteJson($url.'/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('familiares', ['id' => $id]);
    }

    public function test_mutations_validate_input_and_cannot_move_or_change_another_persons_family(): void
    {
        $person = Usuario::factory()->create();
        $other = Usuario::factory()->create();
        $family = Familiar::factory()->create(['usuario_id' => $person->id]);
        $url = '/api/v1/expedientes/'.$person->id.'/familiares';
        $wrong = '/api/v1/expedientes/'.$other->id.'/familiares/'.$family->id;
        $this->patchJson($wrong, ['parentesco' => 'Padre'])->assertNotFound();
        $this->deleteJson($wrong)->assertNotFound();
        $this->patchJson($url.'/'.$family->id, ['usuario_id' => $other->id])->assertUnprocessable();
        $this->patchJson($url.'/'.$family->id, ['registrado_por' => $other->id])->assertUnprocessable();
        $this->patchJson($url.'/'.$family->id, ['fecha_nacimiento' => now()->addDay()->toDateString()])->assertUnprocessable();
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['apellidos_nombres', 'parentesco']);
        $this->postJson($url, ['apellidos_nombres' => 'Prueba', 'parentesco' => 'Padre', 'es_derechohabiente' => false, 'registrado_por' => '00000000-0000-0000-0000-000000000001'])->assertUnprocessable();
    }

    public function test_deletion_preserves_family_with_related_certificates(): void
    {
        $family = Familiar::factory()->create();
        CertificadoDiscapacidad::factory()->create(['usuario_id' => $family->usuario_id, 'familiar_id' => $family->id]);
        $this->deleteJson('/api/v1/expedientes/'.$family->usuario_id.'/familiares/'.$family->id)->assertConflict();
        $this->assertDatabaseHas('familiares', ['id' => $family->id]);
    }

    public function test_family_mutations_are_blocked_in_production(): void
    {
        $this->app->instance('env', 'production');
        $url = '/api/v1/expedientes/00000000-0000-0000-0000-000000000001/familiares';
        $this->postJson($url)->assertForbidden();
        $this->patchJson($url.'/00000000-0000-0000-0000-000000000002')->assertForbidden();
        $this->deleteJson($url.'/00000000-0000-0000-0000-000000000002')->assertForbidden();
    }
}
