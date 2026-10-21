<?php

namespace Tests\Feature;

use App\Models\Plataforma\Institucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsaPlataforma;
use Tests\Fixtures\AdministradorPruebaSeeder as AdministradorSeeder;
use Tests\TestCase;

/**
 * Restablecer desde el panel la contraseña de un administrador institucional cierra sus sesiones abiertas
 * (AuthenticateSession de Sanctum: huella de la contraseña en la sesión) y no toca las de nadie más.
 *
 * Simula navegadores: cada actor guarda sus cookies y las reenvía. Entre peticiones se olvidan los guards y el almacén de
 * sesión, para que todo se lea de la cookie y del archivo de sesión (como en producción, SESSION_DRIVER=file), y no
 * del estado en memoria de la prueba. storage apunta a una carpeta temporal: las sesiones de la institución no se
 * escriben en el storage real.
 */
class SesionRestablecimientoTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const ADMIN = 'admin@uno.test';

    private const CLAVE = 'Clave-inicial-1';

    private const OTRO = 'otra.admin@uno.test';

    private string $base;

    private string $storage;

    private Institucion $uno;

    private string $guardInicial;

    /** @var array<string, array<string, string>> cookies de cada navegador */
    private array $navegadores = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardInicial = (string) config('auth.defaults.guard');
        $this->storage = sys_get_temp_dir().'/sesiones_'.Str::random(8);
        File::ensureDirectoryExists($this->storage.'/framework/sessions');
        $this->app->useStoragePath($this->storage);
        $this->base = tempnam(sys_get_temp_dir(), 'inst_');
        config([
            'session.driver' => 'file', 'session.files' => $this->storage.'/framework/sessions', 'session.encrypt' => true,
            'plataforma.modo' => 'subdominio', 'plataforma.dominio' => 'localhost', 'sanctum.stateful' => ['uno.localhost:5173', 'localhost:5173'],
            'pruebas.administrador' => ['email' => self::ADMIN, 'password' => self::CLAVE],
            'database.connections.institucion_prueba' => ['driver' => 'sqlite', 'database' => $this->base, 'prefix' => '', 'foreign_key_constraints' => true],
        ]);
        $this->artisan('migrate', ['--database' => 'institucion_prueba', '--force' => true])->assertSuccessful();
        $this->artisan('db:seed', ['--class' => AdministradorSeeder::class, '--database' => 'institucion_prueba', '--force' => true])->assertSuccessful();
        $base = DB::connection('institucion_prueba');
        $otro = (string) Str::uuid();
        $base->table('usuarios')->insert(['id' => $otro, 'tipo_doc_identidad' => 'sistema', 'numero_doc_identidad' => 'ADMIN-OTRA', 'nombres' => 'Otra',
            'apellido_paterno' => 'Administradora', 'email_institucional' => self::OTRO, 'password' => Hash::make(self::CLAVE), 'tipo_personal' => 'administrativo',
            'regimen_laboral' => 'No aplica', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        $base->table('usuario_roles')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $otro, 'rol_id' => $base->table('roles')->where('codigo', 'ADMIN')->value('id'),
            'ambito_tipo_personal' => 'ambos', 'vigente_desde' => '2020-01-01', 'asignado_por' => $otro, 'created_at' => now()]);
        DB::purge('institucion_prueba');
        $this->uno = $this->institucion(['slug' => 'uno', 'estado' => Institucion::ACTIVA, 'base_datos' => $this->base, 'aprovisionada_en' => now()]);
        $this->administrador();
    }

    protected function tearDown(): void
    {
        DB::purge('institucion_prueba');
        File::delete($this->base);
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    /** Una petición del navegador $quien: solo con sus cookies, y guarda las que el servidor le devuelva. */
    private function pedir(string $quien, string $metodo, string $url, array $datos = [], bool $plataforma = false): TestResponse
    {
        // Como en producción, cada petición empieza sin estado: guards, almacén de sesión y su instancia compartida
        // (session.store, que usa el guard) se crean de nuevo y solo conocen lo que traen las cookies. El guard por
        // defecto también vuelve al inicial: auth:plataforma lo cambia (shouldUse) y en un proceso nuevo no seguiría así.
        config(['auth.defaults.guard' => $this->guardInicial]);
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->defaultCookies = [];
        $origen = $plataforma ? 'http://localhost:5173' : 'http://uno.localhost:5173';
        // json() solo envía cookies con withCredentials(), como fetch con credentials: 'include'.
        $respuesta = $this->withCredentials()->withCookies($this->navegadores[$quien] ?? [])->withHeaders(['Origin' => $origen, 'Referer' => $origen.'/'])
            ->json($metodo, ($plataforma ? 'http://localhost' : 'http://uno.localhost').$url, $datos);
        foreach ($respuesta->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->navegadores[$quien][$cookie->getName()]);
            } else {
                $this->navegadores[$quien][$cookie->getName()] = $respuesta->getCookie($cookie->getName())->getValue();
            }
        }

        return $respuesta;
    }

    /**
     * Deja la sesión del navegador como quedaban las iniciadas antes de guardar la huella al iniciar sesión: con el
     * usuario autenticado y sin password_hash_web.
     */
    private function quitarHuella(string $quien): void
    {
        $archivo = $this->storage.'/framework/sessions/instituciones/uno/'.$this->navegadores[$quien][config('session.cookie')];
        $sesion = json_decode($this->app['encrypter']->decrypt(File::get($archivo)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('password_hash_web', $sesion);
        unset($sesion['password_hash_web']);
        File::put($archivo, $this->app['encrypter']->encrypt(json_encode($sesion, JSON_THROW_ON_ERROR)));
    }

    private function entrar(string $quien, string $email, string $clave): TestResponse
    {
        return $this->pedir($quien, 'POST', '/api/v1/auth/login', ['email' => $email, 'password' => $clave]);
    }

    /** El superadministrador restablece la contraseña del administrador inicial y devuelve la temporal. */
    private function restablecer(): string
    {
        $this->pedir('panel', 'POST', '/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'], plataforma: true)->assertOk();
        $lista = $this->pedir('panel', 'GET', "/api/plataforma/instituciones/{$this->uno->id}/administradores", plataforma: true)->assertOk();
        $id = collect($lista->json('data'))->firstWhere('email_institucional', self::ADMIN)['id'];

        return $this->pedir('panel', 'POST', "/api/plataforma/instituciones/{$this->uno->id}/administradores/{$id}/restablecer", plataforma: true)
            ->assertOk()->json('data.clave_temporal');
    }

    public function test_resetting_a_password_closes_only_that_administrators_open_sessions(): void
    {
        // Dos navegadores del administrador (uno en uso, otro recién conectado) y uno de otra administradora.
        $this->entrar('admin', self::ADMIN, self::CLAVE)->assertOk();
        $this->pedir('admin', 'GET', '/api/v1/auth/me')->assertOk();
        $this->entrar('admin_recien', self::ADMIN, self::CLAVE)->assertOk();
        $this->entrar('otra', self::OTRO, self::CLAVE)->assertOk();
        $this->pedir('otra', 'GET', '/api/v1/auth/me')->assertOk();

        $temporal = $this->restablecer();

        // Las sesiones anteriores del administrador dejan de valer, también la que solo había iniciado sesión.
        $this->pedir('admin', 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->pedir('admin', 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->pedir('admin_recien', 'GET', '/api/v1/auth/me')->assertUnauthorized();
        // Las demás sesiones siguen: la otra administradora y el superadministrador que restableció.
        $this->pedir('otra', 'GET', '/api/v1/auth/me')->assertOk();
        $this->pedir('panel', 'GET', '/api/plataforma/auth/me', plataforma: true)->assertOk();
        // Una sesión del panel no sirve en la institución, ni una institucional en el panel.
        $this->navegadores['cruzada'] = [...$this->navegadores['panel'], ...$this->navegadores['otra']];
        $this->pedir('cruzada', 'GET', '/api/plataforma/instituciones', plataforma: true)->assertOk();
        $this->navegadores['solo_institucion'] = $this->navegadores['otra'];
        $this->pedir('solo_institucion', 'GET', '/api/plataforma/auth/me', plataforma: true)->assertUnauthorized();

        // La contraseña anterior ya no entra; la temporal sí, y obliga a cambiarla antes de usar el sistema.
        $this->entrar('admin_nuevo', self::ADMIN, self::CLAVE)->assertUnprocessable();
        $this->entrar('admin_nuevo', self::ADMIN, $temporal)->assertOk();
        $this->pedir('admin_nuevo', 'GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.debe_cambiar_password', true);
        $this->pedir('admin_nuevo', 'GET', '/api/v1/legajos')->assertForbidden()->assertJsonPath('codigo', 'cambio_password_requerido');
        $this->pedir('admin_nuevo', 'PUT', '/api/v1/auth/password', ['password_actual' => $temporal, 'password' => 'Clave-Nueva-2026', 'password_confirmation' => 'Clave-Nueva-2026'])->assertOk();
        // Cambiar la propia contraseña no cierra la sesión con la que se cambió.
        $this->pedir('admin_nuevo', 'GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.debe_cambiar_password', false);
        $this->pedir('admin_nuevo', 'GET', '/api/v1/legajos')->assertOk();
    }

    public function test_a_session_opened_before_the_fix_does_not_survive_a_reset(): void
    {
        // Sesión iniciada antes de la corrección: autenticada y sin huella de la contraseña.
        $this->entrar('antigua', self::ADMIN, self::CLAVE)->assertOk();
        $this->quitarHuella('antigua');

        $this->restablecer();

        // Sin huella no se puede saber si la contraseña cambió: no se le deja seguir con la contraseña nueva.
        $this->pedir('antigua', 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->pedir('antigua', 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_a_session_without_password_fingerprint_must_log_in_again(): void
    {
        // Aunque no se restablezca nada: una sesión antigua sin huella vuelve a iniciar sesión una vez y sigue normal.
        $this->entrar('antigua', self::ADMIN, self::CLAVE)->assertOk();
        $this->quitarHuella('antigua');
        $this->entrar('otra', self::OTRO, self::CLAVE)->assertOk();

        $this->pedir('antigua', 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->pedir('otra', 'GET', '/api/v1/auth/me')->assertOk();
        $this->entrar('antigua', self::ADMIN, self::CLAVE)->assertOk();
        $this->pedir('antigua', 'GET', '/api/v1/auth/me')->assertOk();
    }
}
