<?php

namespace Tests\Feature;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\ConexionInstitucion;
use Database\Seeders\AdministradorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UsaPlataforma;
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

    protected function setUp(): void
    {
        parent::setUp();
        config(['plataforma.modo' => 'subdominio', 'plataforma.dominio' => 'localhost', 'sanctum.stateful' => ['*.localhost:5173'],
            'auth.administrador_inicial' => ['email' => self::EMAIL, 'password' => self::CLAVE]]);
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
}
