<?php

namespace Tests\Feature;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\ConexionInstitucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsaPlataforma;
use Tests\Fixtures\AdministradorPruebaSeeder as AdministradorSeeder;
use Tests\TestCase;

/**
 * Identificación de la institución por subdominio (PLATAFORMA_MODO=subdominio): cada subdominio usa su propia base
 * (aquí, archivos SQLite temporales migrados), solo opera una institución activa y el panel solo responde en el
 * dominio base. Con el modo «unica» (por defecto) el resto de pruebas sigue usando la base del .env.
 */
class ResolverInstitucionTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const EMAIL = 'admin@example.test';

    private const CLAVE = 'clave-de-prueba-1';

    /** @var list<string> */
    private array $temporales = [];

    private string $guardInicial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardInicial = (string) config('auth.defaults.guard');
        config(['plataforma.modo' => 'subdominio', 'plataforma.dominio' => 'localhost', 'sanctum.stateful' => ['*.localhost:5173'],
            'pruebas.administrador' => ['email' => self::EMAIL, 'password' => self::CLAVE]]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $archivo) {
            File::delete($archivo);
        }
        parent::tearDown();
    }

    /** Institución activa con su base SQLite migrada; con administrador solo si se pide. */
    private function activa(string $slug, bool $conAdministrador = false, string $estado = Institucion::ACTIVA): Institucion
    {
        $base = tempnam(sys_get_temp_dir(), 'inst_');
        $this->temporales[] = $base;
        config(['database.connections.preparar' => ['driver' => 'sqlite', 'database' => $base, 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->artisan('migrate', ['--database' => 'preparar', '--force' => true])->assertSuccessful();
        if ($conAdministrador) {
            $this->artisan('db:seed', ['--class' => AdministradorSeeder::class, '--database' => 'preparar', '--force' => true])->assertSuccessful();
        }
        DB::purge('preparar');

        return $this->institucion(['slug' => $slug, 'estado' => $estado, 'base_datos' => $base, 'almacenamiento' => 'instituciones/'.$slug, 'aprovisionada_en' => now()]);
    }

    /** @var array<string, array<string, string>> cookies de cada navegador, por subdominio */
    private array $navegadores = [];

    /** Petición del navegador de $slug solo con sus cookies, sin estado de autenticación en memoria. */
    private function navegar(string $slug, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        config(['auth.defaults.guard' => $this->guardInicial]);
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->defaultCookies = [];
        $respuesta = $this->withCredentials()->withCookies($this->navegadores[$slug] ?? [])
            ->withHeaders(['Origin' => "http://{$slug}.localhost:5173", 'Referer' => "http://{$slug}.localhost:5173/"])
            ->json($metodo, "http://{$slug}.localhost{$ruta}", $datos);
        foreach ($respuesta->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->navegadores[$slug][$cookie->getName()]);
            } else {
                $this->navegadores[$slug][$cookie->getName()] = $respuesta->getCookie($cookie->getName())->getValue();
            }
        }

        return $respuesta;
    }

    private function login(string $slug)
    {
        return $this->withHeader('Origin', "http://{$slug}.localhost:5173")
            ->postJson("http://{$slug}.localhost/api/v1/auth/login", ['email' => self::EMAIL, 'password' => self::CLAVE]);
    }

    public function test_each_subdomain_uses_its_own_database(): void
    {
        $this->activa('uno', conAdministrador: true);
        $this->activa('dos');

        // La cuenta solo existe en la base de «uno»; ni «dos» ni la base del .env la conocen.
        $this->login('dos')->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('usuarios', 0);
        $this->login('uno')->assertOk();
        $this->assertSame('sqlite', config('database.default'));
    }

    public function test_team_accesses_and_person_search_are_isolated_by_institution(): void
    {
        config(['session.driver' => 'file']);
        $this->activa('uno', conAdministrador: true);
        $this->activa('dos', conAdministrador: true);
        foreach (['uno', 'dos'] as $slug) {
            $this->navegar($slug, 'POST', '/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::CLAVE])->assertOk();
        }
        $creado = $this->navegar('uno', 'POST', '/api/v1/equipo-accesos', [
            'nombres' => 'Ana', 'apellido_paterno' => 'Quispe', 'numero_doc_identidad' => '99887766',
            'email' => 'equipo@example.test', 'perfil' => 'asistente_legajo',
            'vigente_desde' => today()->toDateString(), 'motivo' => 'Apoyo para la primera institución',
        ])->assertCreated();
        $this->navegar('uno', 'GET', '/api/v1/equipo-accesos')->assertOk()->assertJsonCount(1, 'data.asignaciones');
        $this->navegar('dos', 'GET', '/api/v1/equipo-accesos')->assertOk()->assertJsonCount(0, 'data.asignaciones');
        $this->navegar('dos', 'GET', '/api/v1/equipo-accesos/personas?q=99887766')->assertOk()->assertJsonCount(0, 'data');
        $this->navegar('dos', 'POST', '/api/v1/equipo-accesos', [
            'usuario_id' => $creado->json('data.persona.id'), 'perfil' => 'asistente_legajo',
            'vigente_desde' => today()->toDateString(), 'motivo' => 'Intento de usar una persona de otra institución',
        ])->assertUnprocessable()->assertJsonValidationErrors('usuario_id');
        $this->navegar('dos', 'POST', '/api/v1/equipo-accesos/'.$creado->json('data.id').'/revocar', ['motivo' => 'Intento desde otra institución'])->assertNotFound();
        $this->assertDatabaseCount('usuarios', 0);
    }

    public function test_only_an_active_institution_operates(): void
    {
        $this->activa('suspendida', estado: Institucion::SUSPENDIDA);
        $this->activa('registrada', estado: Institucion::REGISTRADA);
        $this->institucion(['slug' => 'sin-aprovisionar', 'estado' => Institucion::ACTIVA]);

        $this->login('suspendida')->assertForbidden()->assertJsonPath('codigo', 'institucion_suspendida');
        foreach (['registrada', 'sin-aprovisionar', 'no-existe', 'www'] as $slug) {
            $this->login($slug)->assertNotFound()->assertJsonPath('codigo', 'institucion_no_encontrada');
        }
        // Sin subdominio, o con un dominio ajeno, no hay institución.
        $this->postJson('http://localhost/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::CLAVE])->assertNotFound();
        $this->postJson('http://uno.otro-dominio.test/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::CLAVE])->assertNotFound();
    }

    public function test_an_open_session_is_blocked_once_the_institution_is_suspended(): void
    {
        $uno = $this->activa('uno', conAdministrador: true);
        $this->login('uno')->assertOk();
        $this->getJson('http://uno.localhost/api/v1/auth/me')->assertOk();

        $uno->update(['estado' => Institucion::SUSPENDIDA, 'suspendida_en' => now(), 'motivo_suspension' => 'Prueba']);
        $this->getJson('http://uno.localhost/api/v1/auth/me')->assertForbidden()->assertJsonPath('codigo', 'institucion_suspendida');
        $this->getJson('http://uno.localhost/api/v1/legajos')->assertForbidden()->assertJsonPath('codigo', 'institucion_suspendida');
    }

    /** Lista blanca: un estado futuro (archivada, aún no está en el modelo) o desconocido no opera por defecto. */
    public function test_unknown_or_future_states_never_operate(): void
    {
        foreach (['archivada', 'desconocido', 'ACTIVA', ' activa', ''] as $i => $estado) {
            $slug = "estado-{$i}";
            $this->activa($slug, conAdministrador: true, estado: $estado);
            $this->login($slug)->assertForbidden()->assertJsonPath('codigo', 'institucion_no_disponible');
            $this->getJson("http://{$slug}.localhost/api/v1/legajos")->assertForbidden()->assertJsonPath('codigo', 'institucion_no_disponible');
        }
        // El mensaje no revela el estado interno.
        $this->login('estado-0')->assertJsonMissing(['archivada']);
        $this->assertSame(['activa'], Institucion::ESTADOS_OPERATIVOS);
    }

    /**
     * Sesión real de navegador (solo sus cookies, como un proceso nuevo en cada petición): deja de servir en cuanto la
     * institución sale de los estados operativos, y la de otra institución no se ve afectada.
     */
    public function test_an_open_browser_session_is_blocked_once_the_institution_leaves_the_operating_states(): void
    {
        config(['session.driver' => 'file']);
        $uno = $this->activa('uno', conAdministrador: true);
        $this->activa('dos', conAdministrador: true);
        $this->navegar('uno', 'POST', '/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::CLAVE])->assertOk();
        $this->navegar('uno', 'GET', '/api/v1/auth/me')->assertOk();
        $usuarioDos = $this->navegar('dos', 'POST', '/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::CLAVE])->assertOk()->json('data.usuario.id');

        // Escrito directamente en la base, como lo haría una entrega futura que agregue «archivada».
        Institucion::query()->whereKey($uno->id)->update(['estado' => 'archivada']);
        $this->navegar('uno', 'GET', '/api/v1/auth/me')->assertForbidden()->assertJsonPath('codigo', 'institucion_no_disponible');
        $this->navegar('uno', 'GET', '/api/v1/legajos')->assertForbidden();
        // La otra institución sigue funcionando con su propia sesión.
        $this->navegar('dos', 'GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.usuario.id', $usuarioDos);

        // Al volver a un estado operativo, la misma sesión vuelve a servir (nada se borró).
        Institucion::query()->whereKey($uno->id)->update(['estado' => Institucion::ACTIVA]);
        $this->navegar('uno', 'GET', '/api/v1/auth/me')->assertOk();
    }

    public function test_forwarded_headers_do_not_change_the_institution(): void
    {
        $this->activa('uno', conAdministrador: true);
        $this->activa('suspendida', conAdministrador: true, estado: Institucion::SUSPENDIDA);

        // Sin proxies de confianza, X-Forwarded-Host no decide la institución: manda el Host de la petición.
        $falsas = ['X-Forwarded-Host' => 'uno.localhost', 'X-Forwarded-Server' => 'uno.localhost', 'X-Original-Host' => 'uno.localhost'];
        $this->withHeaders([...$falsas, 'Origin' => 'http://suspendida.localhost:5173'])
            ->postJson('http://suspendida.localhost/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::CLAVE])
            ->assertForbidden()->assertJsonPath('codigo', 'institucion_suspendida');
        $this->withHeaders(['X-Forwarded-Host' => 'suspendida.localhost', 'Origin' => 'http://uno.localhost:5173'])
            ->postJson('http://uno.localhost/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::CLAVE])->assertOk();
    }

    public function test_identification_errors_expose_nothing_about_other_institutions(): void
    {
        $this->activa('uno', conAdministrador: true);
        $this->activa('suspendida', estado: Institucion::SUSPENDIDA);
        $this->activa('registrada', estado: Institucion::REGISTRADA);

        foreach (['suspendida' => 403, 'registrada' => 404, 'no-existe' => 404] as $slug => $estado) {
            $respuesta = $this->getJson("http://{$slug}.localhost/api/v1/auth/me")->assertStatus($estado);
            $this->assertSame(['message', 'codigo'], array_keys($respuesta->json()));
            foreach (['uno', 'inst_', 'base_datos', 'instituciones/', sys_get_temp_dir()] as $privado) {
                $this->assertStringNotContainsString($privado, $respuesta->getContent());
            }
        }
        // Una institución registrada y una inexistente responden igual: no se puede saber cuál existe.
        $this->assertSame($this->getJson('http://registrada.localhost/api/v1/auth/me')->getContent(),
            $this->getJson('http://no-existe.localhost/api/v1/auth/me')->getContent());
    }

    public function test_the_platform_panel_only_answers_on_the_base_domain(): void
    {
        $this->activa('uno');

        $this->getJson('http://localhost/api/plataforma/auth/me')->assertUnauthorized();
        $this->getJson('http://uno.localhost/api/plataforma/auth/me')->assertNotFound();
    }

    public function test_the_institution_resources_are_used_only_during_the_request(): void
    {
        $institucion = $this->activa('uno');
        $disco = (string) config('legajo.disco_documentos');
        $raices = collect(config('filesystems.disks'))->map(fn (array $d) => $d['root'] ?? null)->all();

        $dentro = ConexionInstitucion::usar($institucion, fn (): array => [
            DB::connection()->getName(), DB::connection()->getDatabaseName(), app(Institucion::class)->slug,
            Storage::disk($disco)->path(''), Storage::disk('personal_photos')->path(''), Storage::disk('local')->path(''), Storage::disk('public')->path('')]);

        $this->assertSame(ConexionInstitucion::CONEXION, $dentro[0]);
        $this->assertSame($institucion->base_datos, $dentro[1]);
        $this->assertSame('uno', $dentro[2]);
        // Su carpeta es un espejo de storage/app/private: PDF, fotos y PDF anteriores; nada privado queda en común.
        $carpeta = storage_path('app/private/instituciones/uno');
        $this->assertSame($carpeta.'/documentos'.DIRECTORY_SEPARATOR, $dentro[3]);
        $this->assertSame($carpeta.'/fotos-personal'.DIRECTORY_SEPARATOR, $dentro[4]);
        $this->assertSame($carpeta.DIRECTORY_SEPARATOR, $dentro[5]);
        $this->assertSame(storage_path('app/public').DIRECTORY_SEPARATOR, $dentro[6], 'Fuera de private no cambia.');
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame($raices, collect(config('filesystems.disks'))->map(fn (array $d) => $d['root'] ?? null)->all());
        $this->assertFalse(app()->bound(Institucion::class));
    }

    public function test_the_single_institution_mode_changes_nothing(): void
    {
        config(['plataforma.modo' => 'unica']);
        $this->seed(AdministradorSeeder::class);

        $this->withHeader('Origin', 'http://uno.localhost:5173')
            ->postJson('http://cualquiera.localhost/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::CLAVE])->assertOk();
    }

    /**
     * En modo unica no se identifica ninguna institución: su estado en el panel no bloquea el acceso, y el panel no
     * debe decir lo contrario (suspension_bloquea_acceso = false).
     */
    public function test_the_single_institution_mode_does_not_claim_that_a_state_blocks_access(): void
    {
        config(['plataforma.modo' => 'unica']);
        $this->seed(AdministradorSeeder::class);
        $suspendida = $this->institucion(['slug' => 'suspendida', 'estado' => Institucion::SUSPENDIDA, 'aprovisionada_en' => now()]);
        $this->institucion(['slug' => 'archivada', 'estado' => 'archivada', 'aprovisionada_en' => now()]);

        foreach (['suspendida', 'archivada'] as $slug) {
            $this->withHeader('Origin', "http://{$slug}.localhost:5173")
                ->postJson("http://{$slug}.localhost/api/v1/auth/login", ['email' => self::EMAIL, 'password' => self::CLAVE])->assertOk();
        }
        $this->assertFalse($suspendida->datos()['suspension_bloquea_acceso']);
        config(['plataforma.modo' => 'subdominio']);
        $this->assertTrue($suspendida->datos()['suspension_bloquea_acceso']);
    }
}
