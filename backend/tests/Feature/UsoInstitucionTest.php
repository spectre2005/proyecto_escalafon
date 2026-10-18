<?php

namespace Tests\Feature;

use App\Models\Plataforma\Institucion;
use App\Models\Plataforma\Respaldo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/**
 * Uso y estado técnico por institución (UsoInstitucion, GET instituciones/{id}/uso). Cada institución tiene su base
 * SQLite temporal y su carpeta en el storage temporal de las pruebas (tests/bootstrap.php).
 */
class UsoInstitucionTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    /** @var list<string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrador();
        $this->postJson('/api/plataforma/auth/login', ['email' => 'super@example.test', 'password' => 'Clave-segura-2026'])->assertOk();
    }

    protected function tearDown(): void
    {
        DB::purge('preparar');
        foreach ($this->temporales as $ruta) {
            is_dir($ruta) ? File::deleteDirectory($ruta) : File::delete($ruta);
        }
        parent::tearDown();
    }

    /** Institución aprovisionada con su base migrada y su carpeta de archivos. */
    private function con(string $slug, bool $carpeta = true): Institucion
    {
        $this->temporales[] = $base = tempnam(sys_get_temp_dir(), 'inst_');
        DB::purge('preparar');
        config(['database.connections.preparar' => ['driver' => 'sqlite', 'database' => $base, 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->artisan('migrate', ['--database' => 'preparar', '--force' => true])->assertSuccessful();
        if ($carpeta) {
            File::ensureDirectoryExists($this->temporales[] = storage_path('app/private/instituciones/'.$slug));
        }

        return $this->institucion(['nombre' => ucfirst($slug), 'slug' => $slug, 'estado' => Institucion::ACTIVA, 'base_datos' => $base, 'aprovisionada_en' => now()]);
    }

    private function persona(string $dni, bool $activo = true, ?string $acceso = null, bool $admin = false): string
    {
        $db = DB::connection('preparar');
        $id = (string) Str::uuid();
        $db->table('usuarios')->insert(['id' => $id, 'tipo_doc_identidad' => 'DNI', 'numero_doc_identidad' => $dni, 'nombres' => "Nombre{$dni}",
            'apellido_paterno' => "Apellido{$dni}", 'email_institucional' => "p{$dni}@uno.test", 'password' => 'x', 'tipo_personal' => 'docente',
            'regimen_laboral' => 'No aplica', 'activo' => $activo, 'ultimo_acceso' => $acceso, 'created_at' => now(), 'updated_at' => now()]);
        if ($admin) {
            $rol = $db->table('roles')->where('codigo', 'ADMIN')->value('id') ?? tap((string) Str::uuid(), fn (string $r) => $db->table('roles')->insert(
                ['id' => $r, 'codigo' => 'ADMIN', 'nombre' => 'Administrador', 'activo' => true, 've_datos_sensibles' => true]));
            $db->table('usuario_roles')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $id, 'rol_id' => $rol, 'ambito_tipo_personal' => 'ambos',
                'vigente_desde' => '2020-01-01', 'asignado_por' => $id, 'created_at' => now()]);
        }

        return $id;
    }

    private function legajo(string $usuario, string $estado): void
    {
        DB::connection('preparar')->table('legajos')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $usuario, 'numero_legajo' => 'L-'.Str::random(6),
            'estado_legajo' => $estado, 'fecha_apertura' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function uso(Institucion $institucion, bool $actualizar = false): array
    {
        return $this->getJson("/api/plataforma/instituciones/{$institucion->id}/uso".($actualizar ? '?actualizar=1' : ''))->assertOk()->json('data');
    }

    private function estado(array $uso, string $clave): ?string
    {
        return collect($uso['comprobaciones'])->firstWhere('clave', $clave)['estado'] ?? null;
    }

    public function test_counts_and_last_login_come_from_each_institution_database(): void
    {
        $uno = $this->con('uno');
        $ana = $this->persona('40000001', acceso: '2026-10-10 15:00:00', admin: true);
        $beto = $this->persona('40000002', acceso: '2026-10-15 09:30:00');
        $this->persona('40000003', activo: false);
        $this->legajo($ana, 'activo');
        $this->legajo($beto, 'pasivo');
        $this->legajo($beto, 'cerrado');
        $dos = $this->con('dos');
        $this->persona('50000001');

        $u = $this->uso($uno);
        $this->assertSame(['total' => 3, 'con_acceso' => 2], $u['personas']);
        $this->assertSame(['total' => 3, 'activos' => 1, 'pasivos' => 2], $u['legajos'], 'El «cerrado» antiguo cuenta con los pasivos.');
        // El último inicio de sesión de cualquiera (Beto) y el de quien tiene el rol ADMIN (Ana).
        $this->assertSame(['cualquiera' => '2026-10-15T09:30:00+00:00', 'administrador' => '2026-10-10T15:00:00+00:00'], $u['ultimo_ingreso']);
        $this->assertSame(['correcto', 'correcto'], [$this->estado($u, 'base_datos'), $this->estado($u, 'esquema')]);

        // Otra institución, con su propia base: ni sus personas ni su último ingreso se mezclan.
        $d = $this->uso($dos);
        $this->assertSame(['total' => 1, 'con_acceso' => 1], $d['personas']);
        $this->assertSame(['total' => 0, 'activos' => 0, 'pasivos' => 0], $d['legajos']);
        $this->assertSame(['cualquiera' => null, 'administrador' => null], $d['ultimo_ingreso'], 'Sin inicios de sesión, no se inventa una fecha.');
    }

    public function test_storage_counts_only_the_institution_files_once_and_skips_links_outside(): void
    {
        $uno = $this->con('uno');
        $dos = $this->con('dos');
        $carpeta = storage_path('app/private/instituciones/uno');
        File::ensureDirectoryExists("{$carpeta}/documentos/legajos/a");
        File::put("{$carpeta}/documentos/legajos/a/uno.pdf", str_repeat('x', 1000));
        File::put("{$carpeta}/fotos.jpg", str_repeat('y', 500));
        link("{$carpeta}/fotos.jpg", "{$carpeta}/documentos/misma-foto.jpg");          // enlace duro: el mismo archivo
        File::ensureDirectoryExists($this->temporales[] = $afuera = storage_path('app/private/comun'));
        File::put("{$afuera}/grande.pdf", str_repeat('z', 50000));
        symlink($afuera, "{$carpeta}/comun");                                            // enlace a fuera: no se cuenta
        File::put(storage_path('app/private/instituciones/dos/otro.pdf'), str_repeat('w', 7));

        $u = $this->uso($uno);
        $this->assertSame(['bytes' => 1500, 'archivos' => 2, 'parcial' => false, 'enlaces_externos' => 1], $u['almacenamiento']);
        $this->assertSame(['correcto', 'aviso'], [$this->estado($u, 'almacenamiento'), $this->estado($u, 'enlaces')]);
        $this->assertSame(7, $this->uso($dos)['almacenamiento']['bytes']);
    }

    public function test_a_missing_folder_or_database_is_reported_without_mixing_institutions(): void
    {
        $sinCarpeta = $this->con('sin-carpeta', carpeta: false);
        $u = $this->uso($sinCarpeta);
        $this->assertNull($u['almacenamiento']);
        $this->assertSame('error', $this->estado($u, 'almacenamiento'));
        $this->assertSame('correcto', $this->estado($u, 'base_datos'));

        // Una base que no responde: error confirmado, esquema sin verificar y ninguna cifra (no se muestra 0 ni la de otra).
        $sana = $this->con('sana');
        $this->persona('60000001');
        $rota = $this->institucion(['nombre' => 'Rota', 'slug' => 'rota', 'estado' => Institucion::ACTIVA, 'base_datos' => '/no/existe/base.sqlite',
            'almacenamiento' => 'instituciones/rota', 'aprovisionada_en' => now()]);
        $r = $this->uso($rota);
        $this->assertSame(['error', 'no_verificado'], [$this->estado($r, 'base_datos'), $this->estado($r, 'esquema')]);
        $this->assertSame([null, null, null], [$r['personas'], $r['legajos'], $r['ultimo_ingreso']]);
        $this->assertTrue($r['atencion']);
        $this->assertSame(1, $this->uso($sana)['personas']['total'], 'La institución sana sigue con sus propias cifras.');
    }

    public function test_pending_migrations_and_backups_are_reported_as_they_are(): void
    {
        $uno = $this->con('uno');
        $u = $this->uso($uno);
        $this->assertSame(['aviso', 'no_disponible'], [$this->estado($u, 'respaldo'), $this->estado($u, 'copia_externa')], 'Sin respaldos: aviso; la copia externa no se puede revisar.');

        DB::connection('preparar')->table('migrations')->orderByDesc('id')->limit(1)->delete();
        Respaldo::query()->create(['institucion_id' => $uno->id, 'objetivo' => 'uno', 'carpeta' => '20261018-020000', 'estado' => 'correcto', 'copia_externa' => 'fallida']);
        $u = $this->uso($uno, actualizar: true);
        $this->assertSame('aviso', $this->estado($u, 'esquema'));
        $this->assertStringContainsString('1 migración(es) pendiente(s)', collect($u['comprobaciones'])->firstWhere('clave', 'esquema')['mensaje']);
        $this->assertSame(['correcto', 'error'], [$this->estado($u, 'respaldo'), $this->estado($u, 'copia_externa')]);
    }

    public function test_an_unprovisioned_institution_has_nothing_to_measure(): void
    {
        $nueva = $this->institucion(['slug' => 'nueva']);
        $u = $this->uso($nueva);
        $this->assertSame([null, null, null, null, false], [$u['personas'], $u['legajos'], $u['ultimo_ingreso'], $u['almacenamiento'], $u['atencion']]);
        $this->assertSame([['clave' => 'instalacion', 'estado' => 'no_disponible', 'mensaje' => 'Sin aprovisionar: todavía no tiene base de datos ni carpeta.']], $u['comprobaciones']);
    }

    public function test_the_measure_is_cached_and_can_be_refreshed(): void
    {
        $uno = $this->con('uno');
        $this->persona('40000001');
        $primera = $this->uso($uno);
        $this->persona('40000002');
        $this->assertSame([$primera['medido_en'], 1], [$this->uso($uno)['medido_en'], $this->uso($uno)['personas']['total']], 'Sin actualizar, la medición guardada.');
        $this->travel(5)->seconds();
        $this->assertSame(2, $this->uso($uno, actualizar: true)['personas']['total']);
    }

    public function test_no_personal_or_internal_data_is_exposed_and_only_the_panel_can_ask(): void
    {
        $uno = $this->con('uno');
        $this->persona('40000001', acceso: '2026-10-10 15:00:00');
        $rota = $this->institucion(['slug' => 'rota', 'estado' => Institucion::ACTIVA, 'base_datos' => '/no/existe/base.sqlite', 'aprovisionada_en' => now()]);

        foreach ([$uno, $rota] as $institucion) {
            $json = $this->getJson("/api/plataforma/instituciones/{$institucion->id}/uso")->assertOk()->getContent();
            foreach (['40000001', 'Nombre40000001', 'Apellido40000001', 'p40000001@uno.test', $uno->base_datos, '/no/existe', storage_path(), 'SQLSTATE', '"base_datos":'] as $privado) {
                $this->assertStringNotContainsString($privado, $json);
            }
        }

        $this->postJson('/api/plataforma/auth/logout')->assertOk();
        $this->getJson("/api/plataforma/instituciones/{$uno->id}/uso")->assertUnauthorized();
        Sanctum::actingAs(Usuario::factory()->create());
        $this->getJson("/api/plataforma/instituciones/{$uno->id}/uso")->assertUnauthorized();
    }
}
