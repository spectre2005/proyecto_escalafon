<?php

namespace Tests\Feature;

use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;
use Throwable;

/**
 * Aprovisionamiento automático desde el panel y por consola, contra el servidor PostgreSQL de Docker (crea bases reales
 * con nombre aleatorio y las borra al terminar): base migrada con catálogos y primer administrador, carpeta creada,
 * nada de lo existente se toca y, si algo falla a mitad, se retira lo creado.
 */
class AprovisionamientoTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const ADMIN = ['nombres' => 'Ana', 'apellido_paterno' => 'Quispe', 'email' => 'Jefa.ORH@Example.test'];

    private string $sufijo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sufijo = bin2hex(random_bytes(4));
        config(['database.connections.prueba_servidor' => [...config('database.connections.pgsql'), 'database' => 'postgres']]);
        try {
            DB::connection('prueba_servidor')->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('Necesita el servidor PostgreSQL de Docker: '.$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        foreach (DB::connection('prueba_servidor')->select("select datname from pg_database where datname like 'escalafon_aprov_{$this->sufijo}%'") as $fila) {
            DB::connection('prueba_servidor')->statement('DROP DATABASE IF EXISTS "'.$fila->datname.'" WITH (FORCE)');
        }
        DB::purge('prueba_servidor');
        DB::purge('prueba_base');
        foreach (File::glob(storage_path("app/private/instituciones/aprov-{$this->sufijo}*")) as $ruta) {
            is_dir($ruta) ? File::deleteDirectory($ruta) : File::delete($ruta);
        }
        parent::tearDown();
    }

    private function entrar(): void
    {
        $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
    }

    private function registrada(string $nombre = 'a'): Institucion
    {
        $slug = "aprov-{$this->sufijo}-{$nombre}";

        return $this->institucion(['nombre' => 'Universidad Aprovisionada', 'slug' => $slug,
            'base_datos' => "escalafon_aprov_{$this->sufijo}_{$nombre}", 'almacenamiento' => "instituciones/{$slug}"]);
    }

    private function existeBase(string $base): bool
    {
        return DB::connection('prueba_servidor')->selectOne('select 1 from pg_database where datname = ?', [$base]) !== null;
    }

    public function test_the_panel_provisions_database_catalogs_administrator_and_storage(): void
    {
        $institucion = $this->registrada();
        $this->entrar();

        $respuesta = $this->postJson("/api/plataforma/instituciones/{$institucion->id}/aprovisionar", self::ADMIN)->assertOk()
            ->assertJsonPath('data.aprovisionada', true)->assertJsonPath('data.estado', Institucion::REGISTRADA)
            ->assertJsonPath('data.administrador_inicial.email', 'jefa.orh@example.test')->assertHeader('Cache-Control', 'no-store, private');
        $clave = $respuesta->json('data.administrador_inicial.clave_temporal');
        $this->assertSame(16, strlen($clave));

        config(['database.connections.prueba_base' => [...config('database.connections.pgsql'), 'database' => $institucion->base_datos]]);
        $base = DB::connection('prueba_base');
        $this->assertGreaterThan(0, $base->table('tipos_documento')->count());
        $this->assertGreaterThan(0, $base->table('regimenes_normativos')->count());
        $this->assertSame(1, $base->table('usuarios')->count(), 'Sin usuarios de ejemplo: solo el primer administrador.');
        $admin = $base->table('usuarios')->first();
        $this->assertSame('jefa.orh@example.test', $admin->email_institucional);
        $this->assertTrue(Hash::check($clave, $admin->password));
        $this->assertTrue((bool) $admin->debe_cambiar_password, 'La contraseña temporal se cambia al primer ingreso.');
        $this->assertSame('ADMIN', $base->table('usuario_roles')->join('roles', 'roles.id', '=', 'usuario_roles.rol_id')->where('usuario_id', $admin->id)->value('codigo'));
        $this->assertSame('Universidad Aprovisionada', json_decode($base->table('institution_settings')->value('settings'), true)['name']);
        $this->assertDirectoryExists(storage_path('app/private/'.$institucion->almacenamiento));

        $auditoria = AuditoriaPlataforma::query()->where('institucion_id', $institucion->id)->where('accion', 'institucion.aprovisionada')->sole();
        $this->assertStringNotContainsString($clave, json_encode($auditoria->toArray()), 'La contraseña temporal no se audita.');
        // Ya aprovisionada: no se repite y se puede activar.
        $this->postJson("/api/plataforma/instituciones/{$institucion->id}/aprovisionar", self::ADMIN)->assertStatus(409);
        $this->postJson("/api/plataforma/instituciones/{$institucion->id}/activar")->assertOk();
    }

    public function test_an_existing_database_is_never_touched(): void
    {
        $institucion = $this->registrada();
        DB::connection('prueba_servidor')->statement('CREATE DATABASE "'.$institucion->base_datos.'"');
        $this->entrar();

        $this->postJson("/api/plataforma/instituciones/{$institucion->id}/aprovisionar", self::ADMIN)->assertStatus(409)
            ->assertJsonPath('message', "La base {$institucion->base_datos} ya existe. Si se preparó a mano, verifícala con plataforma:verificar-aprovisionamiento.");
        $this->assertTrue($this->existeBase($institucion->base_datos));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/'.$institucion->almacenamiento));
        $this->assertFalse($institucion->fresh()->aprovisionada());
    }

    public function test_a_failure_halfway_removes_what_was_created(): void
    {
        $institucion = $this->registrada();
        // Un archivo donde debería ir la carpeta: falla después de crear, migrar y cargar la base.
        File::ensureDirectoryExists(dirname(storage_path('app/private/'.$institucion->almacenamiento)));
        File::put(storage_path('app/private/'.$institucion->almacenamiento), 'ocupado');
        $this->entrar();

        $this->postJson("/api/plataforma/instituciones/{$institucion->id}/aprovisionar", self::ADMIN)->assertStatus(500);
        $this->assertFalse($this->existeBase($institucion->base_datos), 'La base creada en esta ejecución se retira.');
        $this->assertFileExists(storage_path('app/private/'.$institucion->almacenamiento), 'Lo que ya existía no se toca.');
        $this->assertFalse($institucion->fresh()->aprovisionada());
        $this->assertSame(1, AuditoriaPlataforma::query()->where('institucion_id', $institucion->id)->where('accion', 'institucion.aprovisionamiento_fallido')->count());
    }

    public function test_only_a_registered_institution_with_valid_data_is_provisioned(): void
    {
        $activa = $this->registrada('b');
        $activa->update(['estado' => Institucion::ACTIVA]);
        $this->entrar();

        $this->postJson("/api/plataforma/instituciones/{$activa->id}/aprovisionar", self::ADMIN)->assertStatus(409)
            ->assertJsonPath('message', 'Solo se aprovisiona una institución registrada.');
        $this->postJson("/api/plataforma/instituciones/{$activa->id}/aprovisionar", ['email' => 'no-es-correo', 'clave' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['clave']);
        $this->postJson("/api/plataforma/instituciones/{$activa->id}/aprovisionar", ['email' => 'no-es-correo'])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombres', 'apellido_paterno', 'email']);
        $this->assertFalse($this->existeBase($activa->base_datos));
    }

    public function test_the_console_command_provisions_and_shows_the_temporary_password_once(): void
    {
        $institucion = $this->registrada();

        $this->artisan('plataforma:aprovisionar', ['slug' => $institucion->slug, '--nombres' => 'Ana', '--apellido' => 'Quispe', '--email' => 'jefa@example.test'])
            ->expectsOutputToContain('Contraseña temporal (se muestra solo esta vez)')->assertSuccessful();
        $this->assertTrue($institucion->fresh()->aprovisionada());
        $this->artisan('plataforma:aprovisionar', ['slug' => $institucion->slug, '--nombres' => 'Ana', '--apellido' => 'Quispe', '--email' => 'jefa@example.test'])
            ->expectsOutputToContain('ya está aprovisionada')->assertFailed();
    }
}
