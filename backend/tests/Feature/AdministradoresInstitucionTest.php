<?php

namespace Tests\Feature;

use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use Database\Seeders\AdministradorSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/**
 * Administradores de una institución desde el panel de plataforma (base SQLite temporal y migrada): se listan solo las
 * cuentas de administración, se agregan y restablecen con contraseña temporal (que no se audita), y la institución
 * nunca queda sin un administrador activo.
 */
class AdministradoresInstitucionTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const ADMIN = 'admin@uno.test';

    private string $base;

    private string $persona;

    private Institucion $uno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = tempnam(sys_get_temp_dir(), 'inst_');
        config(['auth.administrador_inicial' => ['email' => self::ADMIN, 'password' => 'Clave-inicial-1'],
            'database.connections.institucion_prueba' => ['driver' => 'sqlite', 'database' => $this->base, 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->artisan('migrate', ['--database' => 'institucion_prueba', '--force' => true])->assertSuccessful();
        $this->artisan('db:seed', ['--class' => AdministradorSeeder::class, '--database' => 'institucion_prueba', '--force' => true])->assertSuccessful();
        // Una persona sin rol: el panel no la ve ni la gestiona.
        DB::connection('institucion_prueba')->table('usuarios')->insert([
            'id' => $this->persona = (string) Str::uuid(), 'tipo_doc_identidad' => 'DNI', 'numero_doc_identidad' => '45678912', 'nombres' => 'Rosa',
            'apellido_paterno' => 'Mamani', 'email_institucional' => 'rosa@uno.test', 'password' => 'x', 'tipo_personal' => 'docente',
            'regimen_laboral' => 'No aplica', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::purge('institucion_prueba');
        $this->uno = $this->institucion(['slug' => 'uno', 'estado' => Institucion::ACTIVA, 'base_datos' => $this->base, 'aprovisionada_en' => now()]);
        $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
    }

    protected function tearDown(): void
    {
        DB::purge('institucion_prueba');
        File::delete($this->base);
        parent::tearDown();
    }

    /**
     * Agrega un administrador con fecha de ayer: su rol queda «vigente desde» ese día. En SQLite la fecha se guarda con
     * hora (2026-10-13 00:00:00) y, comparada como texto, no es <= que «hoy»; en PostgreSQL la columna es date.
     */
    private function agregar(array $datos)
    {
        $this->travel(-1)->days();
        try {
            return $this->postJson($this->url(), $datos);
        } finally {
            $this->travelBack();
        }
    }

    private function url(string $resto = ''): string
    {
        return "/api/plataforma/instituciones/{$this->uno->id}/administradores{$resto}";
    }

    private function enBase(): Connection
    {
        DB::purge('institucion_prueba');

        return DB::connection('institucion_prueba');
    }

    public function test_only_administration_accounts_are_listed(): void
    {
        $lista = $this->getJson($this->url())->assertOk()->json('data');

        $this->assertSame([self::ADMIN], array_column($lista, 'email_institucional'));
        $this->assertSame(['id', 'nombres', 'apellido_paterno', 'email_institucional', 'activo', 'debe_cambiar_password', 'ultimo_acceso'], array_keys($lista[0]));
    }

    public function test_a_new_administrator_gets_a_temporary_password_that_is_not_audited(): void
    {
        $respuesta = $this->agregar(['nombres' => 'Luis', 'apellido_paterno' => 'Huamán', 'email' => 'Luis@Uno.test'])->assertCreated()
            ->assertJsonPath('data.email_institucional', 'luis@uno.test')->assertJsonPath('data.debe_cambiar_password', true)->assertHeader('Cache-Control', 'no-store, private');
        $clave = $respuesta->json('data.clave_temporal');

        $this->assertTrue(Hash::check($clave, $this->enBase()->table('usuarios')->where('email_institucional', 'luis@uno.test')->value('password')));
        $this->assertCount(2, $this->getJson($this->url())->json('data'));
        $auditoria = AuditoriaPlataforma::query()->where('accion', 'administrador_institucion.creado')->sole();
        $this->assertSame($this->uno->id, $auditoria->institucion_id);
        $this->assertStringNotContainsString($clave, json_encode($auditoria->toArray()));

        $this->postJson($this->url(), ['nombres' => 'Otra', 'apellido_paterno' => 'Persona', 'email' => 'rosa@uno.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_a_forgotten_password_is_replaced_by_a_temporary_one(): void
    {
        $admin = $this->getJson($this->url())->json('data.0.id');

        $clave = $this->postJson($this->url("/{$admin}/restablecer"))->assertOk()->assertJsonPath('data.debe_cambiar_password', true)->json('data.clave_temporal');

        $fila = $this->enBase()->table('usuarios')->where('id', $admin)->first();
        $this->assertTrue(Hash::check($clave, $fila->password));
        $this->assertFalse(Hash::check('Clave-inicial-1', $fila->password));
        $this->assertSame(1, AuditoriaPlataforma::query()->where('accion', 'administrador_institucion.clave_restablecida')->count());
        // Una persona sin rol de administración no se gestiona desde el panel.
        $this->postJson($this->url("/{$this->persona}/restablecer"))->assertNotFound();
    }

    public function test_the_institution_never_runs_out_of_active_administrators(): void
    {
        $primero = $this->getJson($this->url())->json('data.0.id');

        $this->postJson($this->url("/{$primero}/desactivar"))->assertStatus(409)->assertJsonPath('message', fn (string $m) => str_contains($m, 'único administrador activo'));
        $segundo = $this->agregar(['nombres' => 'Luis', 'apellido_paterno' => 'Huamán', 'email' => 'luis@uno.test'])->json('data.id');
        $this->postJson($this->url("/{$primero}/desactivar"))->assertOk()->assertJsonPath('data.activo', false);
        $this->postJson($this->url("/{$segundo}/desactivar"))->assertStatus(409);

        // La cuenta desactivada se sigue listando (es de administración) y se puede reactivar.
        $this->assertSame([false, true], collect($this->getJson($this->url())->json('data'))->sortBy('email_institucional')->pluck('activo')->values()->all());
        $this->postJson($this->url("/{$primero}/reactivar"))->assertOk()->assertJsonPath('data.activo', true);
        $this->assertSame(['administrador_institucion.desactivado', 'administrador_institucion.reactivado'],
            AuditoriaPlataforma::query()->whereIn('accion', ['administrador_institucion.desactivado', 'administrador_institucion.reactivado'])->orderBy('created_at')->pluck('accion')->all());
    }

    public function test_an_institution_without_provisioning_has_no_administrators(): void
    {
        $registrada = $this->institucion(['slug' => 'sin-base']);

        $this->getJson("/api/plataforma/instituciones/{$registrada->id}/administradores")->assertStatus(409);
        $this->postJson($this->url(), ['nombres' => 'Luis', 'apellido_paterno' => 'Huamán', 'email' => 'luis@uno.test', 'password' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_existing_person_receives_admin_role_without_duplicate_or_password_changes(): void
    {
        $antes = (array) $this->enBase()->table('usuarios')->where('id', $this->persona)->first();
        $this->getJson($this->url('/persona-existente?email=rosa%40uno.test'))->assertOk()->assertJsonPath('data.id', $this->persona)->assertJsonPath('data.ya_administrador', false)->assertJsonMissingPath('data.password');
        $datos = ['usuario_id' => $this->persona, 'email_confirmado' => 'rosa@uno.test', 'identidad_confirmada' => true, 'motivo' => 'Designación administrativa confirmada'];
        $this->postJson($this->url('/asignar-existente'), [...$datos, 'identidad_confirmada' => false])->assertUnprocessable();
        $this->postJson($this->url('/asignar-existente'), [...$datos, 'email_confirmado' => 'otra@uno.test'])->assertConflict();
        $this->postJson($this->url('/asignar-existente'), $datos)->assertOk()->assertJsonPath('data.id', $this->persona)->assertJsonMissingPath('data.clave_temporal');
        $this->assertSame($antes, (array) $this->enBase()->table('usuarios')->where('id', $this->persona)->first());
        $this->assertSame(2, $this->enBase()->table('usuarios')->count());
        $this->assertCount(2, $this->getJson($this->url())->assertOk()->json('data'));
        $this->postJson($this->url('/asignar-existente'), $datos)->assertConflict();
        $this->assertSame(1, AuditoriaPlataforma::query()->where('accion', 'administrador_institucion.asignado_existente')->count());
    }
}
