<?php

namespace Tests\Feature;

use App\Models\Ubigeo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

class ConsultaDniPersonalTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const URL = '/api/v1/usuarios/consulta-dni';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.factiliza.token' => 'token-solo-pruebas']);
        Http::preventStrayRequests();
    }

    public function test_personnel_registration_autocompletes_names_and_reuses_the_shared_directory(): void
    {
        Http::fake(['api.factiliza.com/*' => Http::response(['success' => true, 'data' => [
            'numero' => '01234567', 'nombres' => 'ANA MARÍA', 'apellido_paterno' => 'QUISPE', 'apellido_materno' => 'FLORES',
            'direccion' => 'Dirección que no se debe transferir', 'ubigeo_reniec' => '010101',
        ]])]);
        $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
        $this->postJson('/api/plataforma/identidad/dni', ['dni' => '01234567'])->assertOk()->assertJsonPath('data.origen', 'factiliza');
        $this->actuarComo();
        $cuentas = Usuario::query()->count();

        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['data' => [
            'dni' => '01234567', 'nombres' => 'ANA MARÍA', 'apellido_paterno' => 'QUISPE', 'apellido_materno' => 'FLORES',
            'origen' => 'base_datos', 'registrada' => false, 'ubicacion' => null,
        ]]);
        Http::assertSentCount(1);
        $this->assertSame($cuentas, Usuario::query()->count(), 'Consultar no crea personas ni accesos.');
    }

    public function test_a_new_dni_can_be_consulted_and_then_reused_without_creating_an_account(): void
    {
        $this->actuarComo();
        Http::fake(['api.factiliza.com/*' => Http::response(['success' => true, 'data' => [
            'numero' => '01234567', 'nombres' => 'ANA MARÍA', 'apellido_paterno' => 'QUISPE',
        ]])]);
        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertJsonPath('data.origen', 'factiliza')->assertJsonPath('data.apellido_materno', null);
        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertJsonPath('data.origen', 'base_datos');
        Http::assertSentCount(1);
        $this->assertFalse(Usuario::query()->where('numero_doc_identidad', '01234567')->exists());
    }

    public function test_an_existing_person_is_identified_without_using_the_provider(): void
    {
        $this->actuarComo();
        $existente = Usuario::factory()->create(['numero_doc_identidad' => '01234567', 'nombres' => 'Ana', 'apellido_paterno' => 'Quispe', 'apellido_materno' => 'Flores']);
        config(['services.factiliza.token' => '']);
        Http::fake();
        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertExactJson(['data' => [
            'dni' => '01234567', 'registrada' => true, 'persona_existente' => ['id' => $existente->id, 'nombre' => 'Ana Quispe Flores'],
        ]]);
        Http::assertNothingSent();
        $this->assertSame(0, DB::connection('plataforma')->table('identidades_dni')->count());
        $this->assertSame(1, Usuario::query()->where('numero_doc_identidad', '01234567')->count());
    }

    public function test_existing_people_are_not_identified_without_permission_to_view_files(): void
    {
        config(['permisos.roles.registro_sin_fichas' => ['usuarios.gestionar', 'legajo.abrir', 'legajo.editar']]);
        $this->actuarComo(null, 'registro_sin_fichas');
        Usuario::factory()->create(['numero_doc_identidad' => '01234567']);
        Http::fake();
        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertExactJson(['data' => ['dni' => '01234567', 'registrada' => true]]);
        Http::assertNothingSent();
    }

    public function test_anonymous_platform_and_unprivileged_accounts_cannot_query_personnel_dni(): void
    {
        Http::fake();
        $this->postJson(self::URL, ['dni' => '01234567'])->assertUnauthorized();
        $this->actingAs($this->administrador(), 'plataforma');
        $this->postJson(self::URL, ['dni' => '01234567'])->assertUnauthorized();
        config(['permisos.roles.lector' => ['personal.ver']]);
        $this->actuarComo(null, 'lector');
        $this->postJson(self::URL, ['dni' => '01234567'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_temporary_password_cannot_use_the_consultation(): void
    {
        $this->actuarComo(Usuario::factory()->create(['debe_cambiar_password' => true]));
        Http::fake();
        $this->postJson(self::URL, ['dni' => '01234567'])->assertForbidden()->assertJsonPath('codigo', 'cambio_password_requerido');
        Http::assertNothingSent();
    }

    public function test_invalid_documents_are_rejected_before_calling_the_provider(): void
    {
        $this->actuarComo();
        Http::fake();
        foreach (['1234567', '123456789', '1234567A', 12345678, null] as $dni) {
            $this->postJson(self::URL, ['dni' => $dni])->assertUnprocessable()->assertJsonValidationErrors('dni');
        }
        Http::assertNothingSent();
    }

    public function test_missing_configuration_allows_the_form_to_fall_back_to_manual_entry(): void
    {
        $this->actuarComo();
        config(['services.factiliza.token' => '']);
        Http::fake();
        $this->postJson(self::URL, ['dni' => '01234567'])->assertStatus(503)->assertJsonPath('message', fn ($mensaje) => str_contains($mensaje, 'manualmente'));
        Http::assertNothingSent();
    }

    private function respuestaConUbicacion(array $ubicacion): array
    {
        return ['success' => true, 'data' => [
            'numero' => '01234567', 'nombres' => 'ANA MARÍA', 'apellido_paterno' => 'QUISPE', 'apellido_materno' => 'FLORES',
            ...$ubicacion,
        ]];
    }

    public function test_location_is_resolved_to_the_institution_catalog_preserving_accents_and_the_local_id(): void
    {
        $this->actuarComo();
        $ubicacion = Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana']);
        Http::fake(['api.factiliza.com/*' => Http::response($this->respuestaConUbicacion([
            'departamento' => ' CUSCO ', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA', 'ubigeo_reniec' => '080901',
        ]))]);

        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertJsonPath('data.ubicacion', $ubicacion->only(['id', 'region', 'provincia', 'distrito']));
        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertJsonPath('data.origen', 'base_datos')
            ->assertJsonPath('data.ubicacion.id', $ubicacion->id);
        Http::assertSentCount(1);
    }

    public function test_missing_or_incomplete_location_does_not_prevent_names_from_autocompleting_or_trigger_repeat_queries(): void
    {
        $this->actuarComo();
        Http::fake(['api.factiliza.com/*' => Http::response($this->respuestaConUbicacion(['departamento' => 'CUSCO', 'provincia' => 'CUSCO']))]);
        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertJsonPath('data.nombres', 'ANA MARÍA')->assertJsonPath('data.ubicacion', null);
        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertJsonPath('data.origen', 'base_datos')->assertJsonPath('data.ubicacion', null);
        Http::assertSentCount(1);
    }

    public function test_an_unknown_or_ambiguous_location_is_not_guessed_or_added_to_the_catalog(): void
    {
        $this->actuarComo();
        Http::fake(['api.factiliza.com/*' => Http::response($this->respuestaConUbicacion([
            'departamento' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA',
        ]))]);
        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertJsonPath('data.ubicacion', null);
        $this->assertSame(0, Ubigeo::query()->count());
        Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana']);
        Ubigeo::factory()->create(['region' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA']);
        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertJsonPath('data.ubicacion', null);
        Http::assertSentCount(1);
    }

    public function test_previous_name_only_entries_are_enriched_once_with_location(): void
    {
        $this->actuarComo();
        DB::connection('plataforma')->table('identidades_dni')->insert([
            'dni' => '01234567', 'nombres' => 'ANA', 'apellido_paterno' => 'QUISPE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ubicacion = Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana']);
        Http::fake(['api.factiliza.com/*' => Http::response($this->respuestaConUbicacion([
            'departamento' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA',
        ]))]);
        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertJsonPath('data.ubicacion.id', $ubicacion->id);
        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertJsonPath('data.origen', 'base_datos')->assertJsonPath('data.ubicacion.id', $ubicacion->id);
        Http::assertSentCount(1);
    }

    public function test_previous_names_remain_available_when_location_enrichment_cannot_run(): void
    {
        $this->actuarComo();
        DB::connection('plataforma')->table('identidades_dni')->insert([
            'dni' => '01234567', 'nombres' => 'ANA', 'apellido_paterno' => 'QUISPE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        config(['services.factiliza.token' => '']);
        Http::fake();
        $this->postJson(self::URL, ['dni' => '01234567'])->assertOk()->assertJsonPath('data.nombres', 'ANA')
            ->assertJsonPath('data.origen', 'base_datos')->assertJsonPath('data.ubicacion', null);
        Http::assertNothingSent();
    }
}
