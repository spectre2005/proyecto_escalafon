<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\ConsultaIdentidadController;
use App\Models\AuditoriaEvento;
use App\Models\Legajo;
use App\Models\Plataforma\Institucion;
use App\Models\Ubigeo;
use App\Models\Usuario;
use App\Support\Identidad\ConsultaDni;
use App\Support\Identidad\ConsultaDniSimulada;
use App\Support\Identidad\ProveedorIdentidad;
use App\Support\Identidad\ResultadoIdentidad;
use App\Support\ModulosSistema;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

class ConsultaIdentidadTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const URL = '/api/v1/identidad/consulta-dni';

    protected function setUp(): void
    {
        parent::setUp();
        config(['identidad.proveedor' => 'simulado', 'identidad.limite_por_usuario' => 10, 'identidad.limite_por_institucion' => 60]);
        // Ninguna prueba sale a la red: una petición HTTP sin respuesta falsa hace fallar la prueba.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(Institucion::class);
        parent::tearDown();
    }

    private function eventos()
    {
        return AuditoriaEvento::query()->where('accion', 'identidad.consultar_dni');
    }

    public function test_a_simulated_match_fills_names_and_catalog_location_marked_as_simulated_and_creates_nothing(): void
    {
        $actor = $this->actuarComo();
        Http::fake();
        $usuarios = Usuario::query()->count();
        $lugar = Ubigeo::factory()->create(['region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana']);

        $this->getJson(self::URL)->assertOk()->assertExactJson(['data' => ['habilitada' => true, 'disponible' => true, 'simulada' => true]]);
        $this->postJson(self::URL, ['dni' => '00000001'])->assertOk()->assertExactJson(['data' => [
            'encontrado' => true, 'simulada' => true, 'nombres' => 'Ana Lucía', 'apellido_paterno' => 'Demostración', 'apellido_materno' => 'Ficticia',
            'ubicacion' => ['id' => $lugar->id, 'region' => 'Cusco', 'provincia' => 'La Convención', 'distrito' => 'Santa Ana'], 'ubicacion_estado' => 'asignada',
        ]]);
        $this->postJson(self::URL, ['dni' => '00000003'])->assertOk()->assertJsonPath('data.apellido_materno', null)
            ->assertJsonPath('data.ubicacion', null)->assertJsonPath('data.ubicacion_estado', 'sin_dato');
        $this->postJson(self::URL, ['dni' => '00000004'])->assertOk()->assertExactJson(['data' => [
            'encontrado' => true, 'simulada' => true, 'nombres' => 'Lucía Fernanda', 'apellido_paterno' => 'Muestra', 'apellido_materno' => 'Simulada',
            'ubicacion' => null, 'ubicacion_estado' => 'sin_coincidencia',
        ]]);

        Http::assertNothingSent();
        $this->assertSame($usuarios, Usuario::query()->count());
        $this->assertSame(0, Legajo::query()->count());
        $evento = $this->eventos()->orderBy('fecha_evento')->firstOrFail();
        $this->assertSame($actor->id, $evento->actor_usuario_id);
        $this->assertSame($actor->id, $evento->entidad_id);
        $this->assertSame('consultas_identidad', $evento->entidad);
        $this->assertNull($evento->usuario_titular_id);
        $this->assertSame(['resultado' => 'coincidencia', 'proveedor' => 'simulado', 'oficial' => false, 'simulada' => true, 'institucion' => null,
            'dni_parcial' => '*****001', 'dni_huella' => ConsultaIdentidadController::huella('00000001')], json_decode($evento->datos_despues, true));
        $this->assertSame(3, $this->eventos()->count());
        // El DNI completo no queda en ninguna columna del evento.
        foreach ($this->eventos()->get() as $registro) {
            $this->assertStringNotContainsString('0000000', json_encode($registro->getAttributes()));
        }
    }

    public function test_the_demonstration_cases_are_fixed_and_fictitious(): void
    {
        // Catálogo cerrado: cambiar o añadir un caso exige actualizar esta prueba y docs/CONSULTA_IDENTIDAD.md.
        $this->assertSame([
            '00000001' => ['nombres' => 'Ana Lucía', 'apellido_paterno' => 'Demostración', 'apellido_materno' => 'Ficticia',
                'ubicacion' => ['departamento' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA']],
            '00000002' => ['nombres' => 'Carlos Alberto', 'apellido_paterno' => 'Prueba', 'apellido_materno' => 'Simulado'],
            '00000003' => ['nombres' => 'María', 'apellido_paterno' => 'Ejemplo', 'apellido_materno' => null],
            '00000004' => ['nombres' => 'Lucía Fernanda', 'apellido_paterno' => 'Muestra', 'apellido_materno' => 'Simulada',
                'ubicacion' => ['departamento' => 'REGION FICTICIA', 'provincia' => 'PROVINCIA FICTICIA', 'distrito' => 'DISTRITO FICTICIO']],
        ], ConsultaDniSimulada::CASOS);
        $this->assertSame('00000009', ConsultaDniSimulada::DNI_NO_DISPONIBLE);
    }

    public function test_a_dni_without_a_demonstration_case_has_no_match(): void
    {
        $this->actuarComo();

        $this->postJson(self::URL, ['dni' => '45678912'])->assertOk()->assertExactJson(['data' => ['encontrado' => false, 'simulada' => true]]);
        $this->assertSame('sin_coincidencia', json_decode($this->eventos()->sole()->datos_despues, true)['resultado']);
    }

    public function test_invalid_dni_and_extra_fields_are_rejected_before_consulting(): void
    {
        $this->actuarComo();
        foreach ([[], ['dni' => ''], ['dni' => '1234567'], ['dni' => '123456789'], ['dni' => 'abcdefgh'], ['dni' => '1234567a'],
            ['dni' => 12345678], ['dni' => '12 345 678'], ['dni' => '１２３４５６７８'], ['dni' => ['00000001']], ['dni' => null]] as $cuerpo) {
            $this->postJson(self::URL, $cuerpo)->assertUnprocessable()->assertJsonValidationErrors('dni');
        }
        $this->postJson(self::URL, ['dni' => '00000001', 'proveedor' => 'otro'])->assertUnprocessable()->assertJsonValidationErrors('proveedor');
        $this->assertSame(0, $this->eventos()->count());
        // Los espacios de los extremos se recortan (TrimStrings) y se consulta el DNI de ocho dígitos.
        $this->postJson(self::URL, ['dni' => " 00000001\n"])->assertOk()->assertJsonPath('data.encontrado', true);
    }

    public function test_without_provider_the_query_is_unavailable_and_nothing_is_consulted(): void
    {
        $this->actuarComo();
        foreach (['ninguno', '', 'pide', 'oficial'] as $proveedor) {
            config(['identidad.proveedor' => $proveedor]);
            $this->getJson(self::URL)->assertOk()->assertExactJson(['data' => ['habilitada' => true, 'disponible' => false, 'simulada' => false]]);
            $this->postJson(self::URL, ['dni' => '00000001'])->assertStatus(503)->assertJsonPath('codigo', 'proveedor_no_configurado')->assertJsonMissingPath('data');
        }
        $this->assertSame(0, $this->eventos()->count());
    }

    public function test_an_unavailable_provider_answers_503_without_leaking_the_dni_and_is_audited(): void
    {
        $this->actuarComo();
        Log::spy();

        $this->postJson(self::URL, ['dni' => ConsultaDniSimulada::DNI_NO_DISPONIBLE])->assertStatus(503)
            ->assertJsonPath('codigo', 'proveedor_no_disponible')->assertJsonMissingPath('data');
        $this->assertSame('proveedor_no_disponible', json_decode($this->eventos()->sole()->datos_despues, true)['resultado']);

        // Un proveedor que falla con el DNI en su mensaje (p. ej. la URL de un cliente HTTP): ni la respuesta ni el log lo llevan.
        $falla = new class implements ConsultaDni
        {
            public function nombre(): string
            {
                return 'prueba';
            }

            public function esOficial(): bool
            {
                return true;
            }

            public function esSimulado(): bool
            {
                return false;
            }

            public function consultar(string $dni): ResultadoIdentidad
            {
                throw new RuntimeException('Tiempo agotado en https://servicio.invalid/dni/'.$dni);
            }
        };
        app()->instance($falla::class, $falla);
        config(['identidad.proveedores.prueba' => $falla::class, 'identidad.proveedor' => 'prueba']);
        $respuesta = $this->postJson(self::URL, ['dni' => '45678912'])->assertStatus(503)->assertJsonPath('codigo', 'proveedor_no_disponible');
        $this->assertStringNotContainsString('45678912', $respuesta->getContent());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $mensaje, array $contexto): bool => ! str_contains($mensaje.json_encode($contexto), '45678912')
            && ! str_contains($mensaje.json_encode($contexto), ConsultaDniSimulada::DNI_NO_DISPONIBLE))->twice();
    }

    public function test_an_official_provider_can_be_plugged_in_by_configuration_and_is_not_marked_as_simulated(): void
    {
        $actor = $this->actuarComo();
        $oficial = new class implements ConsultaDni
        {
            public function nombre(): string
            {
                return 'oficial_prueba';
            }

            public function esOficial(): bool
            {
                return true;
            }

            public function esSimulado(): bool
            {
                return false;
            }

            public function consultar(string $dni): ResultadoIdentidad
            {
                return ResultadoIdentidad::coincidencia('Nombre', 'Paterno', 'Materno');
            }
        };
        app()->instance($oficial::class, $oficial);
        config(['identidad.proveedores.oficial_prueba' => $oficial::class, 'identidad.proveedor' => 'oficial_prueba']);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.simulada', false);
        $this->postJson(self::URL, ['dni' => '45678912'])->assertOk()->assertJsonPath('data.simulada', false)->assertJsonPath('data.nombres', 'Nombre');
        $datos = json_decode($this->eventos()->where('entidad_id', $actor->id)->sole()->datos_despues, true);
        $this->assertTrue($datos['oficial']);
        $this->assertFalse($datos['simulada']);
    }

    public function test_the_simulated_provider_is_blocked_outside_local_and_testing(): void
    {
        $this->actuarComo();
        // Fuera de local la API exige API_HABILITADA (ApiHabilitada) y el token CSRF (solo se omite en testing); aquí se
        // abren para llegar a la consulta.
        config(['app.api_habilitada' => true]);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        foreach (['production', 'staging', ''] as $entorno) {
            $this->app->detectEnvironment(fn (): string => $entorno);
            $this->assertNull(ProveedorIdentidad::actual());
            $this->getJson(self::URL)->assertOk()->assertJsonPath('data.disponible', false);
            $this->postJson(self::URL, ['dni' => '00000001'])->assertStatus(503)->assertJsonPath('codigo', 'proveedor_no_configurado')->assertJsonMissingPath('data');
            try {
                (new ConsultaDniSimulada)->consultar('00000001');
                $this->fail('El simulado respondió fuera de local o pruebas.');
            } catch (LogicException) {
            }
        }
        $this->assertSame(0, $this->eventos()->count());
    }

    public function test_only_users_who_can_register_personnel_may_consult(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, ['dni' => '00000001'])->assertUnauthorized();
        // Sin usuarios.gestionar (ruta) o sin legajo.abrir y legajo.editar (controlador): 403, aunque el DNI sea válido.
        config(['permisos.roles.solo_personal' => ['personal.ver', 'usuarios.gestionar']]);
        foreach (['asistente_legajo', 'solo_personal'] as $rol) {
            $this->actuarComo(codigo: $rol);
            $this->getJson(self::URL)->assertForbidden();
            $this->postJson(self::URL, ['dni' => '00000001'])->assertForbidden()->assertJsonMissingPath('data');
        }
        $this->assertSame(0, $this->eventos()->count());
    }

    public function test_the_institution_can_disable_the_query_and_the_backend_enforces_it(): void
    {
        $this->actuarComo();
        $this->patchJson('/api/v1/configuracion/modulos-sistema/consulta_dni', ['activa' => false, 'activa_actual' => true, 'motivo' => 'Sin convenio de identidad'])
            ->assertOk()->assertJsonPath('data.activa', false);

        $this->getJson(self::URL)->assertOk()->assertExactJson(['data' => ['habilitada' => false, 'disponible' => false, 'simulada' => false]]);
        $this->postJson(self::URL, ['dni' => '00000001'])->assertForbidden()->assertJsonPath('codigo', 'modulo_desactivado')->assertJsonMissingPath('data');
        $this->assertSame(0, $this->eventos()->count());
        // Los demás módulos no cambian.
        $this->getJson('/api/v1/funcionalidades')->assertOk()->assertJsonPath('data.modulos_sistema.evaluaciones', true)->assertJsonPath('data.modulos_sistema.consulta_dni', false);
    }

    public function test_each_institution_uses_only_its_own_setting_and_cannot_choose_another(): void
    {
        $this->actuarComo();
        $uno = $this->institucion(['slug' => 'uno']);
        $dos = $this->institucion(['slug' => 'dos']);
        $uno->forceFill(['modulos_sistema' => ['consulta_dni' => ['activa' => false, 'motivo' => 'Prueba', 'fecha' => now()->toIso8601String(), 'por' => 'Prueba', 'origen' => 'institucion']]])->save();
        // Las instituciones sin ajuste guardado arrancan con el módulo activo, como los demás módulos.
        $this->assertTrue(collect(ModulosSistema::estados($dos))->firstWhere('codigo', 'consulta_dni')['activa']);

        app()->instance(Institucion::class, $uno);
        $this->postJson(self::URL, ['dni' => '00000001'])->assertForbidden()->assertJsonPath('codigo', 'modulo_desactivado');
        foreach (['institucion' => 'dos', 'slug' => 'dos', 'institucion_id' => $dos->id, 'conexion' => 'pgsql', 'base_datos' => $dos->base_datos] as $campo => $valor) {
            app()->instance(Institucion::class, $dos);
            $this->postJson(self::URL, ['dni' => '00000001', $campo => $valor])->assertUnprocessable()->assertJsonValidationErrors($campo);
        }

        app()->instance(Institucion::class, $dos);
        $this->postJson(self::URL, ['dni' => '00000001'])->assertOk()->assertJsonPath('data.encontrado', true);
        $this->assertSame('dos', json_decode($this->eventos()->sole()->datos_despues, true)['institucion']);
        $this->assertFalse(collect(ModulosSistema::estados($uno->refresh()))->firstWhere('codigo', 'consulta_dni')['activa']);
    }

    public function test_queries_are_limited_per_user_and_per_institution(): void
    {
        config(['identidad.limite_por_usuario' => 2, 'identidad.limite_por_institucion' => 3]);
        $primero = $this->actuarComo();
        $this->postJson(self::URL, ['dni' => '00000001'])->assertOk();
        $this->postJson(self::URL, ['dni' => '00000002'])->assertOk();
        $this->postJson(self::URL, ['dni' => '00000003'])->assertStatus(429)->assertJsonPath('codigo', 'limite_consultas')
            ->assertHeader('Retry-After')->assertJsonMissingPath('data');

        // Otra persona de la misma institución tiene su propio límite, pero comparte el de la institución.
        $this->actuarComo(Usuario::factory()->create());
        $this->postJson(self::URL, ['dni' => '00000001'])->assertOk();
        $this->postJson(self::URL, ['dni' => '00000002'])->assertStatus(429)->assertJsonPath('codigo', 'limite_consultas');

        // Las consultas rechazadas por el límite no llegan al proveedor ni se auditan.
        $this->assertSame(3, $this->eventos()->count());
        $this->assertSame(2, $this->eventos()->where('actor_usuario_id', $primero->id)->count());
    }
}
