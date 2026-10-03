<?php

namespace Tests\Feature;

use App\Models\CertificadoDiscapacidad;
use App\Models\Familiar;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamiliarPersonalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actuarComo();
    }

    public function test_family_can_be_created_updated_listed_and_annulled_for_its_owner(): void
    {
        $person = Usuario::factory()->create();
        $registrar = Usuario::factory()->create();
        $this->actuarComo($registrar);
        $url = '/api/v1/expedientes/'.$person->id.'/familiares';
        $id = $this->postJson($url, [
            'apellidos_nombres' => 'Familiar ficticio', 'parentesco' => 'hijo',
            'tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => '00112233',
            'fecha_nacimiento' => '2010-01-01', 'es_derechohabiente' => true,
        ])->assertCreated()->assertJsonPath('data.usuario_id', $person->id)->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.values.apellidos_nombres', 'Familiar ficticio');
        $this->patchJson($url.'/'.$id, ['apellidos_nombres' => 'Familiar actualizado', 'es_derechohabiente' => false, 'fecha_nacimiento' => null])
            ->assertOk()->assertJsonPath('data.es_derechohabiente', false)->assertJsonPath('data.fecha_nacimiento', null);
        $this->assertDatabaseHas('familiares', ['id' => $id, 'registrado_por' => $registrar->id, 'numero_doc_identidad' => '00112233']);

        // No se elimina: se anula con motivo y queda en la base.
        $this->deleteJson($url.'/'.$id)->assertStatus(405);
        $this->postJson($url.'/'.$id.'/anular', [])->assertUnprocessable()->assertJsonValidationErrors('motivo_anulacion');
        $this->postJson($url.'/'.$id.'/anular', ['motivo_anulacion' => 'Registrado en la ficha equivocada'])
            ->assertOk()->assertJsonPath('data.anulado', true)->assertJsonPath('data.motivo_anulacion', 'Registrado en la ficha equivocada');
        $this->postJson($url.'/'.$id.'/anular', ['motivo_anulacion' => 'Otra vez'])->assertConflict();
        $this->patchJson($url.'/'.$id, ['apellidos_nombres' => 'Cambio tardío'])->assertConflict();
        $this->assertDatabaseHas('familiares', ['id' => $id, 'anulado' => true, 'apellidos_nombres' => 'Familiar actualizado']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.values.anulado', true);
    }

    public function test_mutations_validate_input_and_cannot_move_or_change_another_persons_family(): void
    {
        $person = Usuario::factory()->create();
        $other = Usuario::factory()->create();
        $family = Familiar::factory()->create(['usuario_id' => $person->id]);
        $url = '/api/v1/expedientes/'.$person->id.'/familiares';
        $wrong = '/api/v1/expedientes/'.$other->id.'/familiares/'.$family->id;
        $this->patchJson($wrong, ['parentesco' => 'otro'])->assertNotFound();
        $this->postJson($wrong.'/anular', ['motivo_anulacion' => 'Ficha ajena'])->assertNotFound();
        $this->patchJson($url.'/'.$family->id, ['usuario_id' => $other->id])->assertUnprocessable();
        $this->patchJson($url.'/'.$family->id, ['registrado_por' => $other->id])->assertUnprocessable();
        $this->patchJson($url.'/'.$family->id, ['fecha_nacimiento' => now()->addDay()->toDateString()])->assertUnprocessable();
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['apellidos_nombres', 'parentesco']);
        $base = ['apellidos_nombres' => 'Prueba', 'parentesco' => 'otro', 'es_derechohabiente' => false];
        $this->postJson($url, $base + ['registrado_por' => '00000000-0000-0000-0000-000000000001'])->assertUnprocessable();
        // Listas cerradas del esquema.
        $this->postJson($url, ['parentesco' => 'Padre'] + $base)->assertUnprocessable()->assertJsonValidationErrors('parentesco');
        $this->postJson($url, $base + ['tipo_doc_identidad' => 'libreta', 'numero_doc_identidad' => '123'])->assertUnprocessable()->assertJsonValidationErrors('tipo_doc_identidad');
        $this->postJson($url, $base + ['tipo_doc_identidad' => 'dni'])->assertUnprocessable()->assertJsonValidationErrors('numero_doc_identidad');
        $this->postJson($url, $base + ['tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => '1234'])->assertUnprocessable()->assertJsonValidationErrors('numero_doc_identidad');
        $this->postJson($url, $base + ['tipo_doc_identidad' => 'ce', 'numero_doc_identidad' => 'CE-001234'])->assertCreated();
    }

    public function test_annulling_a_family_member_keeps_their_certificates(): void
    {
        $family = Familiar::factory()->create();
        $certificate = CertificadoDiscapacidad::factory()->create(['usuario_id' => $family->usuario_id, 'familiar_id' => $family->id]);
        $this->postJson('/api/v1/expedientes/'.$family->usuario_id.'/familiares/'.$family->id.'/anular', ['motivo_anulacion' => 'Duplicado'])->assertOk();
        $this->assertDatabaseHas('familiares', ['id' => $family->id, 'anulado' => true]);
        $this->assertDatabaseHas('certificados_discapacidad', ['id' => $certificate->id, 'familiar_id' => $family->id]);
    }

    public function test_family_mutations_are_blocked_in_production(): void
    {
        $this->app->instance('env', 'production');
        $url = '/api/v1/expedientes/00000000-0000-0000-0000-000000000001/familiares';
        $this->postJson($url)->assertForbidden();
        $this->patchJson($url.'/00000000-0000-0000-0000-000000000002')->assertForbidden();
        $this->postJson($url.'/00000000-0000-0000-0000-000000000002/anular')->assertForbidden();
    }
}
