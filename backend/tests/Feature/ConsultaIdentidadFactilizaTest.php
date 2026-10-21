<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\ConsultaIdentidadController;
use App\Models\AuditoriaEvento;
use App\Models\Legajo;
use App\Models\Usuario;
use App\Support\Identidad\ConsultaDniFactiliza;
use App\Support\Identidad\ProveedorIdentidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proveedor Factiliza de la consulta de identidad del registro manual. Todas las respuestas son falsas (Http::fake), con
 * DNI y personas ficticias y un token ficticio: ninguna prueba sale a la red ni usa el token real.
 */
class ConsultaIdentidadFactilizaTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/identidad/consulta-dni';

    private const DNI = '00000123';

    private const TOKEN = 'token-ficticio-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();
        config(['identidad.proveedor' => 'factiliza', 'identidad.factiliza.token' => self::TOKEN,
            'identidad.limite_por_usuario' => 10, 'identidad.limite_por_institucion' => 60]);
        Http::preventStrayRequests();
    }

    private function eventos()
    {
        return AuditoriaEvento::query()->where('accion', 'identidad.consultar_dni');
    }

    /** Respuesta con la forma que usa Factiliza (success + data), para el DNI dado. */
    private function respuesta(string $dni = self::DNI, array $datos = []): array
    {
        return ['success' => true, 'data' => [...['numero' => $dni, 'nombres' => 'Rosa Elena', 'apellido_paterno' => 'Ficticia',
            'apellido_materno' => 'De Prueba', 'departamento' => 'CUSCO', 'provincia' => 'LA CONVENCION', 'distrito' => 'SANTA ANA',
            'direccion' => 'Calle Inventada 123'], ...$datos]];
    }

    private function falsear(mixed $respuesta, int $estado = 200): void
    {
        Http::fake([ConsultaDniFactiliza::URL.'*' => Http::response($respuesta, $estado)]);
    }

    public function test_a_match_returns_only_names_not_simulated_and_stores_nothing(): void
    {
        $actor = $this->actuarComo();
        $this->falsear($this->respuesta());
        $usuarios = Usuario::query()->count();

        $this->getJson(self::URL)->assertOk()->assertExactJson(['data' => ['habilitada' => true, 'disponible' => true, 'simulada' => false]]);
        $this->postJson(self::URL, ['dni' => self::DNI])->assertOk()->assertExactJson(['data' => [
            'encontrado' => true, 'simulada' => false, 'nombres' => 'Rosa Elena', 'apellido_paterno' => 'Ficticia', 'apellido_materno' => 'De Prueba',
        ]]);

        // Una sola petición, al endpoint de referencia, con el token del servidor y el DNI en la ruta.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $peticion): bool => $peticion->method() === 'GET' && $peticion->url() === ConsultaDniFactiliza::URL.self::DNI
            && $peticion->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        // Ni ubicación, ni domicilio, ni tabla de identidades: no se crea ni se guarda nada.
        $this->assertSame($usuarios, Usuario::query()->count());
        $this->assertSame(0, Legajo::query()->count());
        $this->assertFalse(Schema::hasTable('identidades_dni'));

        $evento = $this->eventos()->sole();
        $this->assertSame($actor->id, $evento->actor_usuario_id);
        $this->assertSame(['resultado' => 'coincidencia', 'proveedor' => 'factiliza', 'oficial' => false, 'simulada' => false, 'institucion' => null,
            'dni_parcial' => '*****123', 'dni_huella' => ConsultaIdentidadController::huella(self::DNI)], json_decode($evento->datos_despues, true));
        $fila = json_encode($evento->getAttributes());
        $this->assertStringNotContainsString(self::DNI, $fila);
        $this->assertStringNotContainsString('Rosa Elena', $fila);
        $this->assertStringNotContainsString(self::TOKEN, $fila);
    }

    public function test_a_missing_mother_surname_is_empty_and_names_are_trimmed(): void
    {
        $this->actuarComo();
        $this->falsear($this->respuesta(datos: ['nombres' => '  Rosa Elena ', 'apellido_materno' => '']));

        $this->postJson(self::URL, ['dni' => self::DNI])->assertOk()->assertJsonPath('data.nombres', 'Rosa Elena')->assertJsonPath('data.apellido_materno', null);
    }

    public function test_a_dni_unknown_to_the_provider_has_no_match(): void
    {
        $this->actuarComo();
        $this->falsear(['success' => false, 'message' => 'No encontrado'], 404);

        $this->postJson(self::URL, ['dni' => self::DNI])->assertOk()->assertExactJson(['data' => ['encontrado' => false, 'simulada' => false]]);
        $this->assertSame('sin_coincidencia', json_decode($this->eventos()->sole()->datos_despues, true)['resultado']);
    }

    public function test_an_invalid_dni_never_reaches_the_provider(): void
    {
        $this->actuarComo();
        Http::fake();
        foreach (['', '1234567', '123456789', 'abcdefgh', '../00000', '00000123?x=1'] as $dni) {
            $this->postJson(self::URL, ['dni' => $dni])->assertUnprocessable()->assertJsonValidationErrors('dni');
        }
        Http::assertNothingSent();
        $this->assertSame(0, $this->eventos()->count());
    }

    public function test_without_token_there_is_no_provider_and_nothing_is_sent(): void
    {
        $this->actuarComo();
        Http::fake();
        foreach (['', '   ', null] as $token) {
            config(['identidad.factiliza.token' => $token]);
            $this->assertNull(ProveedorIdentidad::actual());
            $this->getJson(self::URL)->assertOk()->assertExactJson(['data' => ['habilitada' => true, 'disponible' => false, 'simulada' => false]]);
            $this->postJson(self::URL, ['dni' => self::DNI])->assertStatus(503)->assertJsonPath('codigo', 'proveedor_no_configurado')->assertJsonMissingPath('data');
        }
        Http::assertNothingSent();
        $this->assertSame(0, $this->eventos()->count());
    }

    public function test_errors_timeouts_and_auth_failures_answer_503_without_leaking_dni_or_token(): void
    {
        $this->actuarComo();
        Log::spy();
        // Un caso por consulta, en orden: sin conexión, token revocado, sin permiso, límite del plan y servicio caído.
        Http::fake([ConsultaDniFactiliza::URL.'*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Operation timed out for '.ConsultaDniFactiliza::URL.self::DNI)
            ->push(['success' => false, 'message' => 'Token inválido'], 401)
            ->push(['success' => false], 403)
            ->push(['success' => false], 429)
            ->push('<html>Error</html>', 500)]);
        for ($caso = 1; $caso <= 5; $caso++) {
            $respuesta = $this->postJson(self::URL, ['dni' => self::DNI])->assertStatus(503)->assertJsonPath('codigo', 'proveedor_no_disponible')->assertJsonMissingPath('data');
            $this->assertStringNotContainsString(self::DNI, $respuesta->getContent(), "caso {$caso}");
            $this->assertStringNotContainsString(self::TOKEN, $respuesta->getContent(), "caso {$caso}");
        }
        Http::assertSentCount(5);
        $this->assertSame(5, $this->eventos()->where('datos_despues', 'like', '%proveedor_no_disponible%')->count());
        // Nueve avisos (uno del controlador por caso y uno de Factiliza por cada código de error), ninguno con el DNI o el token.
        Log::shouldHaveReceived('warning')->times(9);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $mensaje, array $contexto = []): bool => ! str_contains($mensaje.json_encode($contexto), self::DNI)
            && ! str_contains($mensaje.json_encode($contexto), self::TOKEN))->times(9);
        // El código HTTP sí queda, para distinguir un token revocado (401) de un servicio caído (500).
        foreach ([401, 403, 429, 500] as $estado) {
            Log::shouldHaveReceived('warning')->withArgs(fn (string $mensaje, array $contexto = []): bool => ($contexto['estado'] ?? null) === $estado)->once();
        }
    }

    public function test_an_unexpected_or_foreign_response_is_never_used(): void
    {
        $this->actuarComo();
        $invalidas = [
            'otro DNI' => $this->respuesta('00000456'),
            'sin success' => ['data' => $this->respuesta()['data']],
            'success falso' => ['success' => false, 'data' => $this->respuesta()['data']],
            'sin nombres' => $this->respuesta(datos: ['nombres' => '']),
            'sin apellido paterno' => $this->respuesta(datos: ['apellido_paterno' => null]),
            'nombres que no son texto' => $this->respuesta(datos: ['nombres' => ['Rosa']]),
            'nombres demasiado largos' => $this->respuesta(datos: ['nombres' => str_repeat('a', 121)]),
            'data vacía' => ['success' => true, 'data' => null],
            'no es JSON' => 'respuesta sin formato',
        ];
        $secuencia = Http::sequence();
        foreach ($invalidas as $cuerpo) {
            $secuencia->push($cuerpo);
        }
        Http::fake([ConsultaDniFactiliza::URL.'*' => $secuencia]);
        foreach (array_keys($invalidas) as $caso) {
            $this->postJson(self::URL, ['dni' => self::DNI])->assertStatus(503)->assertJsonPath('codigo', 'proveedor_no_disponible')
                ->assertJsonMissingPath('data.nombres');
        }
        Http::assertSentCount(count($invalidas));
        $this->assertSame(count($invalidas), $this->eventos()->count());
    }

    public function test_limits_stop_queries_before_they_reach_the_provider(): void
    {
        config(['identidad.limite_por_usuario' => 2]);
        $this->actuarComo();
        $this->falsear($this->respuesta());

        $this->postJson(self::URL, ['dni' => self::DNI])->assertOk();
        $this->postJson(self::URL, ['dni' => self::DNI])->assertOk();
        $this->postJson(self::URL, ['dni' => self::DNI])->assertStatus(429)->assertJsonPath('codigo', 'limite_consultas')->assertHeader('Retry-After');
        Http::assertSentCount(2);
    }

    public function test_only_users_who_can_register_personnel_may_consult(): void
    {
        Http::fake();
        $this->postJson(self::URL, ['dni' => self::DNI])->assertUnauthorized();
        config(['permisos.roles.solo_personal' => ['personal.ver', 'usuarios.gestionar']]);
        foreach (['asistente_legajo', 'solo_personal'] as $rol) {
            $this->actuarComo(codigo: $rol);
            $this->postJson(self::URL, ['dni' => self::DNI])->assertForbidden()->assertJsonMissingPath('data');
        }
        Http::assertNothingSent();
        $this->assertSame(0, $this->eventos()->count());
    }

    public function test_the_query_exists_only_for_the_manual_registration(): void
    {
        // Ni edición del legajo ni formularios de plataforma: solo GET/POST /api/v1/identidad/consulta-dni.
        $rutas = collect(Route::getRoutes()->getRoutes())->filter(fn ($ruta): bool => str_contains($ruta->uri(), 'dni'))
            ->map(fn ($ruta): string => implode('|', $ruta->methods()).' '.$ruta->uri())->sort()->values()->all();
        $this->assertSame(['GET|HEAD api/v1/identidad/consulta-dni', 'POST api/v1/identidad/consulta-dni'], $rutas);
    }
}
