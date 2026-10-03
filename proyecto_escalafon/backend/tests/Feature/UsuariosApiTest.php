<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\Usuario;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsuariosApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actuarComo();
    }

    /** Cuenta usuarios sin el usuario autenticado que crea setUp(). */
    private function assertUsuariosCreados(int $count): void
    {
        $this->assertDatabaseCount('usuarios', $count + 1);
    }

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return [...Usuario::factory()->make()->only([
            'numero_doc_identidad', 'nombres', 'apellido_paterno', 'email_institucional',
            'tipo_personal', 'regimen_laboral',
        ]), ...$overrides];
    }

    public function test_manual_record_persists_and_is_returned_by_a_later_request(): void
    {
        $row = $this->payload(['numero_doc_identidad' => '00123456', 'email_institucional' => 'ANA@uniq.edu.pe']);
        $response = $this->postJson('/api/v1/usuarios', ['usuarios' => [$row]])
            ->assertCreated()->assertJsonPath('data.0.numero_doc_identidad', '00123456')
            ->assertJsonPath('data.0.email_institucional', 'ana@uniq.edu.pe')
            ->assertJsonPath('data.0.activo', false);
        $this->assertArrayNotHasKey('password', $response->json('data.0'));
        $record = Usuario::query()->findOrFail($response->json('data.0.id'));
        $this->assertNotSame('unknown', Hash::info($record->password)['algoName']);
        $listed = collect($this->getJson('/api/v1/usuarios')->assertOk()->json('data'))->firstWhere('id', $record->id);
        $this->assertSame('', $listed['apellido_materno']);
        $this->assertSame('', $listed['telefono']);
    }

    public function test_bulk_records_persist_together(): void
    {
        $this->postJson('/api/v1/usuarios', ['usuarios' => [$this->payload(), $this->payload()]])
            ->assertCreated()->assertJsonCount(2, 'data');
        $this->assertUsuariosCreados(2);
    }

    public function test_invalid_row_prevents_the_whole_batch_from_being_saved(): void
    {
        $this->postJson('/api/v1/usuarios', ['usuarios' => [
            $this->payload(), $this->payload(['numero_doc_identidad' => '123']),
        ]])->assertUnprocessable()->assertJsonValidationErrors('usuarios.1.numero_doc_identidad');
        $this->assertUsuariosCreados(0);
    }

    public function test_regime_must_belong_to_the_staff_type(): void
    {
        $this->postJson('/api/v1/usuarios', ['usuarios' => [
            $this->payload(['tipo_personal' => 'docente', 'regimen_laboral' => '30220']),
            $this->payload(['tipo_personal' => 'administrativo', 'regimen_laboral' => '30057']),
        ]])->assertCreated();

        $this->postJson('/api/v1/usuarios', ['usuarios' => [
            $this->payload(['tipo_personal' => 'docente', 'regimen_laboral' => '276']),
            $this->payload(['tipo_personal' => 'administrativo', 'regimen_laboral' => '30220']),
            $this->payload(['tipo_personal' => 'administrativo', 'regimen_laboral' => 'Otro']),
        ]])->assertUnprocessable()->assertJsonValidationErrors([
            'usuarios.0.regimen_laboral', 'usuarios.1.regimen_laboral', 'usuarios.2.regimen_laboral',
        ]);
        $this->assertUsuariosCreados(2);
    }

    public function test_duplicates_in_the_file_are_rejected_case_insensitively(): void
    {
        $this->postJson('/api/v1/usuarios', ['usuarios' => [
            $this->payload(['email_institucional' => 'Ana@uniq.edu.pe']),
            $this->payload(['email_institucional' => 'ana@uniq.edu.pe']),
        ]])->assertUnprocessable()->assertJsonValidationErrors('usuarios.1.email_institucional');
        $this->assertUsuariosCreados(0);
    }

    public function test_existing_document_and_email_cannot_be_reimported(): void
    {
        $existing = Usuario::factory()->create(['email_institucional' => 'Ana@uniq.edu.pe']);
        $this->postJson('/api/v1/usuarios', ['usuarios' => [
            $this->payload(['numero_doc_identidad' => $existing->numero_doc_identidad]),
        ]])->assertUnprocessable()->assertJsonValidationErrors('usuarios.0.numero_doc_identidad');
        $this->postJson('/api/v1/usuarios', ['usuarios' => [
            $this->payload(['email_institucional' => 'ANA@uniq.edu.pe']),
        ]])->assertUnprocessable()->assertJsonValidationErrors('usuarios.0.email_institucional');
        $this->assertUsuariosCreados(1);
    }

    public function test_extra_fields_and_empty_batches_are_rejected(): void
    {
        $this->postJson('/api/v1/usuarios', ['usuarios' => [$this->payload(['activo' => true])]])
            ->assertUnprocessable()->assertJsonValidationErrors('usuarios.0');
        $this->postJson('/api/v1/usuarios', ['usuarios' => []])->assertUnprocessable();
        $this->assertUsuariosCreados(0);
    }

    public function test_list_is_paginated_and_never_exposes_passwords(): void
    {
        Usuario::factory()->count(101)->create();
        $response = $this->getJson('/api/v1/usuarios')->assertOk()
            ->assertJsonCount(100, 'data')->assertJsonPath('meta.last_page', 2);
        $this->assertArrayNotHasKey('password', $response->json('data.0'));
        // 101 creados + el usuario autenticado del test.
        $this->getJson('/api/v1/usuarios?page=2')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_database_index_prevents_duplicate_emails_even_without_api_validation(): void
    {
        Usuario::factory()->create(['email_institucional' => 'Ana@uniq.edu.pe']);
        $this->expectException(UniqueConstraintViolationException::class);
        Usuario::factory()->create(['email_institucional' => 'ana@uniq.edu.pe']);
    }

    public function test_an_insert_failure_rolls_back_previously_inserted_chunks(): void
    {
        DB::statement("CREATE TRIGGER reject_test_record BEFORE INSERT ON usuarios WHEN NEW.numero_doc_identidad = '99999999' BEGIN SELECT RAISE(ABORT, 'Test insert failure'); END");
        $rows = [];
        for ($i = 0; $i < 101; $i++) {
            $rows[] = $this->payload(['numero_doc_identidad' => sprintf('%08d', $i)]);
        }
        $rows[100]['numero_doc_identidad'] = '99999999';
        $this->postJson('/api/v1/usuarios', ['usuarios' => $rows])->assertStatus(500);
        $this->assertUsuariosCreados(0);
    }

    public function test_user_can_be_read_updated_and_deleted(): void
    {
        $usuario = Usuario::factory()->create();
        $uri = '/api/v1/usuarios/'.$usuario->id;
        $this->getJson($uri)->assertOk()->assertJsonPath('data.id', $usuario->id);
        $this->patchJson($uri, ['nombres' => 'Ana', 'email_institucional' => 'ANA@uniq.edu.pe'])
            ->assertOk()->assertJsonPath('data.nombres', 'Ana')
            ->assertJsonPath('data.email_institucional', 'ana@uniq.edu.pe');
        $this->assertDatabaseHas('usuarios', ['id' => $usuario->id, 'nombres' => 'Ana']);
        $this->patchJson($uri, ['activo' => true])->assertUnprocessable();
        $other = Usuario::factory()->create();
        $this->patchJson($uri, ['email_institucional' => strtoupper($other->email_institucional)])
            ->assertUnprocessable();
        $this->deleteJson($uri)->assertNoContent();
        $this->getJson($uri)->assertNotFound();
    }

    public function test_user_with_related_records_cannot_be_deleted(): void
    {
        $legajo = Legajo::factory()->create();
        $this->deleteJson('/api/v1/usuarios/'.$legajo->usuario_id)->assertConflict();
        $this->assertDatabaseHas('usuarios', ['id' => $legajo->usuario_id]);
    }

    public function test_api_is_disabled_outside_local_development(): void
    {
        $this->app->instance('env', 'production');
        $this->getJson('/api/v1/usuarios')->assertForbidden();
        $this->postJson('/api/v1/usuarios', ['usuarios' => [$this->payload()]])->assertForbidden();
        $this->assertUsuariosCreados(0);
    }
}
