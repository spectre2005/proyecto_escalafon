<?php

namespace Tests\Feature;

use App\Models\RegimenNormativo;
use App\Models\Ubigeo;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use App\Support\Identidad\ConsultaDniFactiliza;
use App\Support\Identidad\UbicacionCatalogo;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Registro de personal con fecha de nacimiento, domicilio y la ubicación propuesta por la consulta de identidad. Datos
 * ficticios y respuestas HTTP falsas: ninguna petición sale a la red.
 */
class RegistroDatosPersonalesTest extends TestCase
{
    use RefreshDatabase;

    private const ALTA = '/api/v1/usuarios/con-vinculo';

    private const IMPORTACION = '/api/v1/usuarios';

    private const CONSULTA = '/api/v1/identidad/consulta-dni';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        Http::preventStrayRequests();
        // Mismo registro que el administrador, pero sin datos sensibles (ve_datos_sensibles solo lo tiene ADMIN).
        config(['permisos.roles.prueba_registro' => ['usuarios.gestionar', 'personal.ver', 'legajo.ver', 'legajo.abrir', 'legajo.editar']]);
    }

    private function persona(array $datos = []): array
    {
        return ['numero_doc_identidad' => '71000001', 'nombres' => 'ROSA', 'apellido_paterno' => 'FICTICIA', 'apellido_materno' => 'PRUEBA',
            'email_institucional' => 'rosa.ficticia@example.test', 'tipo_personal' => 'docente',
            'regimen_normativo_id' => RegimenNormativo::query()->where('codigo', '30220')->value('id'), ...$datos];
    }

    private function alta(array $persona = []): array
    {
        return ['usuarios' => [$this->persona($persona)],
            'vinculo' => ['regimen_laboral' => 'carrera', 'nombre_puesto' => 'DOCENTE DEL DEPARTAMENTO', 'fecha_inicio' => '2026-03-01',
                'unidad_organica_id' => UnidadOrganica::factory()->create()->id, 'numero_documento_vinculo' => 'RES-001-2026', 'tiene_periodo_prueba' => false],
            'datos_puesto' => ['condicion_docente' => 'ordinario', 'categoria' => 'asociado', 'dedicacion' => 'tiempo_completo']];
    }

    private function factiliza(array $datos = []): void
    {
        config(['identidad.proveedor' => 'factiliza', 'identidad.factiliza.token' => 'token-ficticio']);
        Http::fake([ConsultaDniFactiliza::URL.'*' => Http::response(['success' => true, 'data' => [
            'numero' => '71000001', 'nombres' => 'ROSA', 'apellido_paterno' => 'FICTICIA', 'apellido_materno' => 'PRUEBA', ...$datos]])]);
    }

    public function test_manual_registration_saves_birth_date_and_address_and_does_not_return_them(): void
    {
        $this->actuarComo();

        $respuesta = $this->postJson(self::ALTA, $this->alta(['fecha_nacimiento' => '1980-03-15', 'domicilio' => '  JR. FICTICIO 123  ']))->assertCreated();

        $persona = Usuario::query()->where('numero_doc_identidad', '71000001')->firstOrFail();
        $this->assertSame(['1980-03-15', 'JR. FICTICIO 123'], [$persona->fecha_nacimiento?->toDateString(), $persona->domicilio]);
        // La respuesta del alta no devuelve datos sensibles.
        $respuesta->assertJsonMissingPath('data.usuario.fecha_nacimiento')->assertJsonMissingPath('data.usuario.domicilio');
        $this->assertStringNotContainsString('1980-03-15', $respuesta->getContent());
        $this->assertStringNotContainsString('FICTICIO 123', $respuesta->getContent());
    }

    public function test_both_fields_are_optional(): void
    {
        $this->actuarComo();

        $this->postJson(self::ALTA, $this->alta())->assertCreated();
        $this->postJson(self::ALTA, $this->alta(['numero_doc_identidad' => '71000002', 'email_institucional' => 'otra@example.test', 'fecha_nacimiento' => null, 'domicilio' => '']))->assertCreated();

        $this->assertSame([[null, null], [null, null]], Usuario::query()->whereIn('numero_doc_identidad', ['71000001', '71000002'])->orderBy('numero_doc_identidad')->get()
            ->map(fn (Usuario $u): array => [$u->fecha_nacimiento, $u->domicilio])->all());
    }

    public function test_invalid_or_future_birth_date_and_long_address_are_rejected(): void
    {
        $this->actuarComo();

        foreach (['15/03/1980', '1980-02-30', 'mañana', now('America/Lima')->addDays(2)->toDateString()] as $fecha) {
            $this->postJson(self::ALTA, $this->alta(['fecha_nacimiento' => $fecha]))->assertUnprocessable()->assertJsonValidationErrors('usuarios.0.fecha_nacimiento');
        }
        $this->postJson(self::ALTA, $this->alta(['domicilio' => str_repeat('A', 251)]))->assertUnprocessable()->assertJsonValidationErrors('usuarios.0.domicilio');
        $this->assertSame(0, Usuario::query()->where('numero_doc_identidad', '71000001')->count());
    }

    public function test_without_sensitive_data_permission_registration_works_but_those_fields_are_refused(): void
    {
        $this->actuarComo(codigo: 'prueba_registro');

        $this->postJson(self::ALTA, $this->alta(['fecha_nacimiento' => '1980-03-15']))->assertUnprocessable()
            ->assertJsonPath('errors', ['usuarios.0.fecha_nacimiento' => ['Registro 1: registrar la fecha de nacimiento requiere el permiso de datos sensibles.']]);
        $this->postJson(self::IMPORTACION, ['usuarios' => [$this->persona(['domicilio' => 'JR. FICTICIO 123'])]])->assertUnprocessable()
            ->assertJsonValidationErrors('usuarios.0.domicilio');
        $this->assertSame(0, Usuario::query()->where('numero_doc_identidad', '71000001')->count());

        // Sin esos campos, el registro habitual sigue igual.
        $this->postJson(self::ALTA, $this->alta())->assertCreated();
    }

    public function test_bulk_import_accepts_the_new_fields_and_rows_without_them(): void
    {
        $this->actuarComo();

        $this->postJson(self::IMPORTACION, ['usuarios' => [
            $this->persona(['fecha_nacimiento' => '1975-11-02', 'domicilio' => 'AV. INVENTADA 45']),
            $this->persona(['numero_doc_identidad' => '71000002', 'email_institucional' => 'plantilla.anterior@example.test']),
        ]])->assertCreated()->assertJsonMissingPath('data.0.fecha_nacimiento');

        $rosa = Usuario::query()->where('numero_doc_identidad', '71000001')->firstOrFail();
        $this->assertSame(['1975-11-02', 'AV. INVENTADA 45'], [$rosa->fecha_nacimiento?->toDateString(), $rosa->domicilio]);
        $this->assertNull(Usuario::query()->where('numero_doc_identidad', '71000002')->value('fecha_nacimiento'));
        // Error con su fila (registro) y su campo.
        $this->postJson(self::IMPORTACION, ['usuarios' => [$this->persona(['numero_doc_identidad' => '71000003', 'email_institucional' => 'a@example.test']),
            $this->persona(['numero_doc_identidad' => '71000004', 'email_institucional' => 'b@example.test', 'fecha_nacimiento' => '2999-01-01'])]])
            ->assertUnprocessable()->assertJsonValidationErrors('usuarios.1.fecha_nacimiento');
    }

    public function test_the_generic_user_update_does_not_open_a_path_to_these_fields(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['fecha_nacimiento' => null, 'domicilio' => null]);

        $this->patchJson('/api/v1/usuarios/'.$persona->id, ['fecha_nacimiento' => '1980-03-15'])->assertUnprocessable()->assertJsonPath('errors.fecha_nacimiento.0', 'Campo no permitido.');
        $this->patchJson('/api/v1/usuarios/'.$persona->id, ['domicilio' => 'JR. FICTICIO 1'])->assertUnprocessable();
        $this->assertNull($persona->refresh()->fecha_nacimiento);
    }

    public function test_location_with_a_unique_match_is_proposed_from_the_catalog(): void
    {
        $this->actuarComo();
        $lugar = Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana']);
        Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Echarate']);
        $this->factiliza(['departamento' => 'CUSCO', 'provincia' => 'LA  CONVENCION ', 'distrito' => 'SANTA ANA', 'direccion' => 'JR. NO SE USA 9', 'fecha_nacimiento' => '01/01/1970']);

        $this->postJson(self::CONSULTA, ['dni' => '71000001'])->assertOk()
            ->assertJsonPath('data.ubicacion', ['id' => $lugar->id, 'region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana'])
            ->assertJsonPath('data.ubicacion_estado', 'asignada')
            // Dirección y fecha de nacimiento del proveedor no se usan (no están confirmadas en el plan).
            ->assertJsonMissingPath('data.direccion')->assertJsonMissingPath('data.fecha_nacimiento');
    }

    public function test_location_without_match_or_ambiguous_is_left_for_manual_selection(): void
    {
        $this->actuarComo();
        $this->factiliza(['departamento' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA']);
        $this->postJson(self::CONSULTA, ['dni' => '71000001'])->assertOk()->assertJsonPath('data.ubicacion', null)->assertJsonPath('data.ubicacion_estado', 'sin_coincidencia');

        // Dos filas que solo difieren en mayúsculas, tildes o espacios: no se elige ninguna.
        Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana']);
        Ubigeo::factory()->create(['region' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA  ANA']);
        $this->postJson(self::CONSULTA, ['dni' => '71000001'])->assertOk()->assertJsonPath('data.ubicacion', null)->assertJsonPath('data.ubicacion_estado', 'ambigua');

        // Mismo distrito en otra provincia: no se aproxima.
        $this->assertSame(UbicacionCatalogo::SIN_COINCIDENCIA, UbicacionCatalogo::emparejar(['departamento' => 'CUSCO', 'provincia' => 'CALCA', 'distrito' => 'SANTA ANA'])['estado']);
    }

    public function test_a_response_with_names_but_no_complete_location_still_fills_the_names(): void
    {
        $this->actuarComo();
        $this->factiliza(['departamento' => 'CUSCO', 'provincia' => '', 'distrito' => null]);

        $this->postJson(self::CONSULTA, ['dni' => '71000001'])->assertOk()->assertJsonPath('data.nombres', 'ROSA')
            ->assertJsonPath('data.ubicacion', null)->assertJsonPath('data.ubicacion_estado', 'sin_dato');
    }

    public function test_without_sensitive_data_permission_the_lookup_returns_no_location(): void
    {
        $this->actuarComo(codigo: 'prueba_registro');
        Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana']);
        $this->factiliza(['departamento' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA']);
        $consultas = 0;
        DB::listen(function ($consulta) use (&$consultas): void {
            $consultas += (int) str_contains($consulta->sql, 'ubigeo');
        });

        $respuesta = $this->postJson(self::CONSULTA, ['dni' => '71000001'])->assertOk()->assertJsonPath('data.nombres', 'ROSA')
            ->assertJsonMissingPath('data.ubicacion')->assertJsonMissingPath('data.ubicacion_estado');
        $this->assertStringNotContainsString('Cusco', $respuesta->getContent());
        $this->assertSame(0, $consultas);
    }

    public function test_provider_failure_keeps_the_manual_registration_working(): void
    {
        $this->actuarComo();
        config(['identidad.proveedor' => 'factiliza', 'identidad.factiliza.token' => 'token-ficticio']);
        Http::fake([ConsultaDniFactiliza::URL.'*' => Http::response(null, 500)]);

        $this->postJson(self::CONSULTA, ['dni' => '71000001'])->assertStatus(503)->assertJsonPath('codigo', 'proveedor_no_disponible');
        $this->postJson(self::ALTA, $this->alta(['fecha_nacimiento' => '1980-03-15']))->assertCreated();
    }

    public function test_there_is_no_identity_lookup_in_the_legajo_edition_and_no_shared_storage(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $this->factiliza(['departamento' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA']);

        // No existe (405: solo la lectura genérica de secciones coincide con esa dirección, y es GET).
        $this->assertContains($this->postJson('/api/v1/expedientes/'.$persona->id.'/consulta-dni', ['dni' => '71000001'])->status(), [404, 405]);
        Http::assertNothingSent();
        $rutas = collect(Route::getRoutes()->getRoutes())->map(fn ($ruta) => $ruta->uri())->filter(fn (string $uri): bool => str_contains($uri, 'consulta-dni'))->values()->all();
        $this->assertSame(['api/v1/identidad/consulta-dni'], array_values(array_unique($rutas)));

        $tablas = fn (): array => collect(Schema::getTableListing())->sort()->values()->all();
        $antes = $tablas();
        $this->postJson(self::CONSULTA, ['dni' => '71000001'])->assertOk();
        $this->assertSame($antes, $tablas());
        $this->assertFalse(Schema::hasTable('identidades_dni'));
        $this->assertFalse(Schema::connection('plataforma')->hasTable('identidades_dni'));
        $this->assertSame(0, Usuario::query()->where('numero_doc_identidad', '71000001')->count());
    }
}
