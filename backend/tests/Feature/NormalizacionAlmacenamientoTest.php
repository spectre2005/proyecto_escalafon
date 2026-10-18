<?php

namespace Tests\Feature;

use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\AlmacenamientoUnica;
use App\Support\Plataforma\ConexionInstitucion;
use App\Support\Plataforma\InventarioArchivos;
use App\Support\Plataforma\NormalizacionAlmacenamiento;
use App\Support\Plataforma\NormalizacionRechazada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/**
 * Inventario y normalización del almacenamiento (InventarioArchivos, NormalizacionAlmacenamiento y
 * plataforma:normalizar-almacenamiento), en el storage temporal de PHPUnit (tests/bootstrap.php) y con bases SQLite
 * temporales: ningún archivo ni base real.
 *
 * «uniq» reproduce el caso del entorno de desarrollo: su carpeta tiene enlaces a las carpetas comunes. «otra» es una
 * institución con carpeta propia que no debe verse afectada.
 */
class NormalizacionAlmacenamientoTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    private const DNI = '45678912';

    /** @var list<string> */
    private array $temporales = [];

    private string $privado;

    protected function setUp(): void
    {
        parent::setUp();
        // Nunca el storage real: el de cada ejecución de PHPUnit.
        if (! str_starts_with(storage_path(), sys_get_temp_dir().'/escalafon-pruebas-')) {
            throw new RuntimeException('Estas pruebas solo corren en el storage temporal de PHPUnit.');
        }
        $this->privado = storage_path('app/private');
        config(['plataforma.modo' => 'subdominio']);
    }

    protected function tearDown(): void
    {
        DB::purge('preparar');
        // Primero las carpetas con enlaces (deleteDirectory borra el enlace, no su destino).
        foreach (['instituciones', 'documentos', 'fotos-personal', 'certificados-discapacidad', 'declaraciones-verificaciones'] as $carpeta) {
            File::deleteDirectory($this->privado.'/'.$carpeta);
        }
        File::deleteDirectory(NormalizacionAlmacenamiento::raiz());
        foreach ($this->temporales as $archivo) {
            File::delete($archivo);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Institución con base SQLite migrada; el estado y la carpeta se preparan en cada prueba. */
    private function institucionCon(string $slug, string $estado = Institucion::SUSPENDIDA): Institucion
    {
        $this->temporales[] = $base = tempnam(sys_get_temp_dir(), 'inst_');
        DB::purge('preparar');
        config(['database.connections.preparar' => ['driver' => 'sqlite', 'database' => $base, 'prefix' => '', 'foreign_key_constraints' => false]]);
        $this->artisan('migrate', ['--database' => 'preparar', '--force' => true])->assertSuccessful();

        return $this->institucion(['nombre' => ucfirst($slug), 'slug' => $slug, 'estado' => $estado, 'base_datos' => $base, 'aprovisionada_en' => now()]);
    }

    private function base(Institucion $institucion)
    {
        DB::purge('preparar');
        config(['database.connections.preparar' => ['driver' => 'sqlite', 'database' => $institucion->base_datos, 'prefix' => '', 'foreign_key_constraints' => false]]);

        return DB::connection('preparar');
    }

    private function archivo(string $relativa, string $contenido): string
    {
        File::ensureDirectoryExists(dirname($this->privado.'/'.$relativa));
        file_put_contents($this->privado.'/'.$relativa, $contenido);

        return $contenido;
    }

    private function pdf(Institucion $institucion, string $ruta, string $contenido, ?int $tamano = null): void
    {
        $this->base($institucion)->table('archivos_documento')->insert(['id' => (string) Str::uuid(), 'almacenamiento' => 'documentos', 'storage_path' => $ruta,
            'nombre_original' => 'Documento.pdf', 'tamano_bytes' => $tamano ?? strlen($contenido), 'hash_sha256' => hash('sha256', $contenido), 'subido_por' => (string) Str::uuid()]);
    }

    private function persona(Institucion $institucion, ?string $foto = null, string $dni = self::DNI): string
    {
        $id = (string) Str::uuid();
        $this->base($institucion)->table('usuarios')->insert(['id' => $id, 'tipo_doc_identidad' => 'DNI', 'numero_doc_identidad' => $dni, 'nombres' => 'Nombre',
            'apellido_paterno' => 'Apellido', 'email_institucional' => Str::random(6).'@uno.test', 'password' => 'x', 'tipo_personal' => 'docente',
            'regimen_laboral' => 'No aplica', 'activo' => true, 'foto_path' => $foto, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function certificadoAntiguo(Institucion $institucion, string $usuario, string $ruta): void
    {
        $this->base($institucion)->table('certificados_discapacidad')->insert(['id' => (string) Str::uuid(), 'usuario_id' => $usuario, 'persona' => 'titular',
            'numero_conadis' => '123', 'registrado_por' => $usuario, 'pdf_path' => $ruta, 'pdf_nombre' => 'certificado.pdf', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * «uniq» como en desarrollo: dos PDF, una foto (con el DNI en su nombre) y un PDF antiguo del disco local en las
     * carpetas comunes, y enlaces relativos desde su carpeta.
     */
    private function uniqConEnlaces(string $estado = Institucion::SUSPENDIDA): Institucion
    {
        $uniq = $this->institucionCon('uniq', $estado);
        $usuario = $this->persona($uniq, $foto = 'p1/f1/img_'.self::DNI.'.jpg');
        $this->archivo('fotos-personal/'.$foto, 'foto-jpg');
        $this->archivo('fotos-personal/.gitignore', "*\n!.gitignore\n");
        foreach (['legajos/l1/seccion-01/a.pdf' => 'pdf-uno', 'legajos/l1/seccion-03/b.pdf' => 'pdf-dos'] as $ruta => $contenido) {
            $this->pdf($uniq, $ruta, $this->archivo('documentos/'.$ruta, $contenido));
        }
        $this->certificadoAntiguo($uniq, $usuario, 'certificados-discapacidad/'.$usuario.'/c.pdf');
        $this->archivo('certificados-discapacidad/'.$usuario.'/c.pdf', 'pdf-antiguo');
        File::ensureDirectoryExists($this->privado.'/instituciones/uniq');
        foreach (['documentos', 'fotos-personal', 'certificados-discapacidad'] as $carpeta) {
            symlink('../../'.$carpeta, $this->privado.'/instituciones/uniq/'.$carpeta);
        }

        return $uniq;
    }

    /** «otra», con carpeta propia y un PDF suyo. */
    private function otra(): Institucion
    {
        $otra = $this->institucionCon('otra', Institucion::ACTIVA);
        $this->pdf($otra, 'legajos/x/seccion-01/z.pdf', $this->archivo('instituciones/otra/documentos/legajos/x/seccion-01/z.pdf', 'pdf-de-otra'));

        return $otra;
    }

    /** Huella del árbol (sin seguir enlaces): tipo, tamaño, contenido y destino de cada entrada. */
    private function arbol(string $relativa): string
    {
        $raiz = $this->privado.'/'.$relativa;
        if (! file_exists($raiz) && ! is_link($raiz)) {
            return 'no-existe';
        }
        $lineas = [];
        $recorrido = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($recorrido as $entrada) {
            $ruta = substr($entrada->getPathname(), strlen($raiz));
            $lineas[] = $entrada->isLink() ? "l {$ruta} ".readlink($entrada->getPathname())
                : ($entrada->isDir() ? "d {$ruta}" : "f {$ruta} ".hash_file('sha256', $entrada->getPathname()));
        }
        sort($lineas);

        return hash('sha256', implode("\n", $lineas));
    }

    /** @return array<string, string> */
    private function originales(): array
    {
        return collect(['documentos', 'fotos-personal', 'certificados-discapacidad', 'instituciones/otra'])->mapWithKeys(fn (string $c): array => [$c => $this->arbol($c)])->all();
    }

    private function ciclo(Institucion $institucion): string
    {
        $preparacion = NormalizacionAlmacenamiento::preparar($institucion);
        NormalizacionAlmacenamiento::verificar($institucion, $preparacion);
        NormalizacionAlmacenamiento::cambiar($institucion, $preparacion);

        return $preparacion;
    }

    public function test_the_inventory_finds_every_kind_of_reference_through_the_links(): void
    {
        $uniq = $this->uniqConEnlaces();
        $this->otra();

        $inventario = InventarioArchivos::de($uniq);

        $this->assertTrue($inventario['apto']);
        $this->assertSame(['enlazado' => 4], collect($inventario['referencias'])->countBy('estado')->all());
        $this->assertEqualsCanonicalizing(['archivos_documento', 'archivos_documento', 'usuarios', 'certificados_discapacidad'], collect($inventario['referencias'])->pluck('fuente')->all());
        foreach ($inventario['referencias'] as $r) {
            $this->assertSame(hash_file('sha256', $this->privado.'/'.$r['fisico']), $r['sha256_actual']);
            $this->assertSame(filesize($this->privado.'/'.$r['fisico']), $r['bytes']);
        }
        $this->assertSame(['comun' => 3], collect($inventario['enlaces'])->countBy('tipo')->all());
        // Los archivos de «otra» y el .gitignore no son huérfanos de uniq; nada sin referencia.
        $this->assertSame([], collect($inventario['huerfanos'])->where('zona', '!=', 'comun')->all());
        $resumen = InventarioArchivos::resumen($inventario);
        $this->assertStringNotContainsString(self::DNI, json_encode($resumen));
        $this->assertStringNotContainsString('legajos/', json_encode($resumen));
    }

    public function test_the_default_command_only_inventories_and_prints_no_paths(): void
    {
        $uniq = $this->uniqConEnlaces();
        $antes = [...$this->originales(), 'uniq' => $this->arbol('instituciones/uniq')];

        $this->artisan('plataforma:normalizar-almacenamiento', ['slug' => 'uniq'])
            ->expectsOutputToContain('simulación: no se copió ni se cambió nada')
            ->expectsOutputToContain('Normalizaría «documentos» (hoy: enlace): 2 archivo(s)')
            ->doesntExpectOutputToContain(self::DNI)
            ->doesntExpectOutputToContain('legajos/')
            ->assertSuccessful();

        $this->assertSame($antes, [...$this->originales(), 'uniq' => $this->arbol('instituciones/uniq')]);
        $this->assertDirectoryDoesNotExist(NormalizacionAlmacenamiento::raiz());
        $this->assertSame(0, AuditoriaPlataforma::query()->count());
        $this->assertTrue(is_link($this->privado.'/instituciones/uniq/documentos'));
        $this->assertSame(Institucion::SUSPENDIDA, $uniq->refresh()->estado);
    }

    /** Equipo de un compañero: carpeta propia vacía y los archivos en las carpetas comunes (modo unica). */
    public function test_files_saved_in_single_mode_are_found_with_an_empty_own_folder(): void
    {
        $uniq = $this->institucionCon('uniq');
        $this->pdf($uniq, 'legajos/l1/seccion-01/a.pdf', $this->archivo('documentos/legajos/l1/seccion-01/a.pdf', 'pdf-uno'));
        File::ensureDirectoryExists($this->privado.'/instituciones/uniq');

        $inventario = InventarioArchivos::de($uniq);
        $this->assertTrue($inventario['apto']);
        $this->assertSame('comun', $inventario['referencias'][0]['estado']);
        $plan = NormalizacionAlmacenamiento::plan($inventario);
        $this->assertSame(['documentos'], array_keys($plan));
        $this->assertSame('no_existe', $plan['documentos']['actual']);

        $this->ciclo($uniq);

        $this->assertSame('propio', InventarioArchivos::de($uniq)['referencias'][0]['estado']);
        $this->assertSame('pdf-uno', file_get_contents($this->privado.'/documentos/legajos/l1/seccion-01/a.pdf'), 'El original sigue en su lugar.');
    }

    public function test_missing_altered_shared_and_orphan_files_block_the_normalization(): void
    {
        $uniq = $this->uniqConEnlaces();
        $this->pdf($uniq, 'legajos/l1/seccion-05/falta.pdf', 'nunca-guardado');
        $this->pdf($uniq, 'legajos/l1/seccion-06/cambiado.pdf', $this->archivo('documentos/legajos/l1/seccion-06/cambiado.pdf', 'contenido'), tamano: 999);
        $this->archivo('documentos/legajos/sin-dueno/huerfano.pdf', 'huerfano');
        // Otra institución enlazada a la misma carpeta común y con una referencia al mismo PDF que uniq.
        $otra = $this->institucionCon('otra', Institucion::ACTIVA);
        $this->pdf($otra, 'legajos/l1/seccion-01/a.pdf', 'pdf-uno');
        File::ensureDirectoryExists($this->privado.'/instituciones/otra');
        symlink('../../documentos', $this->privado.'/instituciones/otra/documentos');

        $inventario = InventarioArchivos::de($uniq);

        $this->assertFalse($inventario['apto']);
        $this->assertSame(['alterado' => 1, 'compartido' => 1, 'faltante' => 1, 'huerfano_enlazado' => 1], $inventario['bloqueos']);
        $this->artisan('plataforma:normalizar-almacenamiento', ['slug' => 'uniq'])->expectsOutputToContain('No se puede normalizar')->assertFailed();
        $this->expectException(NormalizacionRechazada::class);
        NormalizacionAlmacenamiento::preparar($uniq);
    }

    /** La base principal (la del .env, sin institución) también es dueña de archivos de las carpetas comunes. */
    public function test_a_file_also_referenced_by_the_main_database_is_shared(): void
    {
        $uniq = $this->uniqConEnlaces();
        DB::table('usuarios')->insert(['id' => (string) Str::uuid(), 'tipo_doc_identidad' => 'DNI', 'numero_doc_identidad' => '11111111', 'nombres' => 'N',
            'apellido_paterno' => 'A', 'email_institucional' => 'principal@uno.test', 'password' => 'x', 'tipo_personal' => 'docente', 'regimen_laboral' => 'No aplica',
            'activo' => true, 'foto_path' => 'p1/f1/img_'.self::DNI.'.jpg', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['compartido' => 1], InventarioArchivos::de($uniq)['bloqueos']);
    }

    public function test_paths_outside_the_institution_are_rejected(): void
    {
        $uniq = $this->uniqConEnlaces();
        $otra = $this->otra();
        $this->pdf($uniq, '../instituciones/otra/documentos/legajos/x/seccion-01/z.pdf', 'pdf-de-otra');
        // Un enlace a la carpeta de otra institución, y una ruta del disco local hacia la zona de instituciones.
        symlink('../otra/documentos', $this->privado.'/instituciones/uniq/ajena');
        $usuario = $this->persona($uniq, dni: '22222222');
        $this->certificadoAntiguo($uniq, $usuario, 'instituciones/otra/documentos/legajos/x/seccion-01/z.pdf');

        $inventario = InventarioArchivos::de($uniq);

        $this->assertEqualsCanonicalizing(['ruta_insegura', 'ruta_de_otra_zona'], collect($inventario['referencias'])->where('estado', 'ambiguo')->pluck('motivo')->all());
        $this->assertSame(['ambiguo' => 2, 'enlace_ajeno' => 1], $inventario['bloqueos']);
        $this->assertSame('activa', $otra->refresh()->estado);
    }

    public function test_a_full_normalization_keeps_every_file_reference_and_the_originals(): void
    {
        $uniq = $this->uniqConEnlaces();
        $this->otra();
        $antes = InventarioArchivos::de($uniq);
        $originales = $this->originales();

        $preparacion = $this->ciclo($uniq);

        // Carpetas reales, sin enlaces, con las mismas huellas.
        foreach (['documentos', 'fotos-personal', 'certificados-discapacidad'] as $carpeta) {
            $this->assertFalse(is_link($this->privado.'/instituciones/uniq/'.$carpeta));
            $this->assertDirectoryExists($this->privado.'/instituciones/uniq/'.$carpeta);
        }
        $despues = InventarioArchivos::de($uniq);
        $this->assertTrue($despues['apto']);
        $this->assertSame(['propio' => 4], collect($despues['referencias'])->countBy('estado')->all());
        $this->assertSame(collect($antes['referencias'])->pluck('sha256_actual', 'ruta')->sort()->all(), collect($despues['referencias'])->pluck('sha256_actual', 'ruta')->sort()->all());
        $this->assertSame([], $despues['enlaces']);
        // Originales y la otra institución, intactos; el manifiesto, privado.
        $this->assertSame($originales, $this->originales());
        $carpeta = NormalizacionAlmacenamiento::raiz().'/'.$preparacion;
        $this->assertSame('0700', substr(sprintf('%o', fileperms($carpeta)), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($carpeta.'/manifiesto.json')), -4));
        $this->assertStringNotContainsString('/private/instituciones/', $carpeta);
        $this->assertSame('cambiada', NormalizacionAlmacenamiento::leerEstado($carpeta)['etapa']);
    }

    /** La aplicación lee y guarda en la carpeta propia de la institución después del cambio. */
    public function test_documents_photos_and_old_pdfs_are_read_and_new_uploads_land_in_the_own_folder(): void
    {
        $uniq = $this->uniqConEnlaces();
        $this->ciclo($uniq);
        $usuario = $this->base($uniq)->table('usuarios')->value('id');

        ConexionInstitucion::usar($uniq, function () use ($usuario): void {
            $this->assertSame('pdf-uno', Storage::disk('documentos')->get('legajos/l1/seccion-01/a.pdf'));
            $this->assertSame('foto-jpg', Storage::disk('personal_photos')->get('p1/f1/img_'.self::DNI.'.jpg'));
            $this->assertSame('pdf-antiguo', Storage::disk('local')->get('certificados-discapacidad/'.$usuario.'/c.pdf'));
            Storage::disk('documentos')->put('legajos/l1/seccion-02/nuevo.pdf', 'pdf-nuevo');
            Storage::disk('personal_photos')->put('p1/f2/nueva.jpg', 'foto-nueva');
        });

        $this->assertFileExists($this->privado.'/instituciones/uniq/documentos/legajos/l1/seccion-02/nuevo.pdf');
        $this->assertFileDoesNotExist($this->privado.'/documentos/legajos/l1/seccion-02/nuevo.pdf');
        $this->assertFileExists($this->privado.'/instituciones/uniq/fotos-personal/p1/f2/nueva.jpg');
        $this->assertFileDoesNotExist($this->privado.'/fotos-personal/p1/f2/nueva.jpg');
    }

    public function test_reverting_restores_the_links_and_the_previous_inventory(): void
    {
        $uniq = $this->uniqConEnlaces();
        $antes = InventarioArchivos::de($uniq)['huella'];
        $preparacion = $this->ciclo($uniq);

        NormalizacionAlmacenamiento::revertir($uniq, $preparacion);

        $this->assertTrue(is_link($this->privado.'/instituciones/uniq/documentos'));
        $this->assertSame($antes, InventarioArchivos::de($uniq)['huella']);
        $this->assertDirectoryExists(NormalizacionAlmacenamiento::raiz().'/'.$preparacion.'/preparacion/documentos', 'La copia se conserva.');
    }

    public function test_reverting_refuses_when_new_files_arrived_after_the_change(): void
    {
        $uniq = $this->uniqConEnlaces();
        $preparacion = $this->ciclo($uniq);
        $this->archivo('instituciones/uniq/documentos/legajos/l1/seccion-02/nuevo.pdf', 'pdf-nuevo');

        try {
            NormalizacionAlmacenamiento::revertir($uniq, $preparacion);
            $this->fail('Debió rechazar la reversión.');
        } catch (NormalizacionRechazada $rechazo) {
            $this->assertStringContainsString('1 archivo(s) nuevo(s)', $rechazo->getMessage());
        }
        $this->assertFileExists($this->privado.'/instituciones/uniq/documentos/legajos/l1/seccion-02/nuevo.pdf');
    }

    public function test_an_interrupted_preparation_changes_nothing_and_cannot_be_used(): void
    {
        $uniq = $this->uniqConEnlaces();
        $antes = [...$this->originales(), 'uniq' => $this->arbol('instituciones/uniq')];

        try {
            NormalizacionAlmacenamiento::preparar($uniq, function (string $carpeta, int $copiados): void {
                if ($copiados === 2) {
                    throw new RuntimeException('Corte simulado');
                }
            });
            $this->fail('Debió interrumpirse.');
        } catch (NormalizacionRechazada $rechazo) {
            $this->assertStringContainsString('se interrumpió', $rechazo->getMessage());
        }

        $preparacion = NormalizacionAlmacenamiento::ultima($uniq);
        $this->assertSame('preparacion_fallida', NormalizacionAlmacenamiento::leerEstado(NormalizacionAlmacenamiento::raiz().'/'.$preparacion)['etapa']);
        $this->assertSame($antes, [...$this->originales(), 'uniq' => $this->arbol('instituciones/uniq')]);
        $this->expectException(NormalizacionRechazada::class);
        NormalizacionAlmacenamiento::verificar($uniq, $preparacion);
    }

    /** Un destino que ya existe (aquí, un enlace a un original puesto a mitad de la copia) no se sobrescribe. */
    public function test_the_copy_never_writes_over_an_existing_destination(): void
    {
        $uniq = $this->uniqConEnlaces();
        $antes = $this->originales();

        try {
            NormalizacionAlmacenamiento::preparar($uniq, function (string $carpeta, int $copiados): void {
                if ($copiados === 1) {
                    $destino = NormalizacionAlmacenamiento::raiz().'/'.NormalizacionAlmacenamiento::ultima(Institucion::query()->where('slug', 'uniq')->first())
                        .'/preparacion/documentos/legajos/l1/seccion-01/a.pdf';
                    File::ensureDirectoryExists(dirname($destino));
                    symlink($this->privado.'/fotos-personal/p1/f1/img_'.self::DNI.'.jpg', $destino);
                }
            });
            $this->fail('Debió rechazar la copia.');
        } catch (NormalizacionRechazada $rechazo) {
            $this->assertStringContainsString('la copia ya existía', $rechazo->getMessage());
        }
        $this->assertSame($antes, $this->originales());
    }

    public function test_a_failed_verification_blocks_the_change(): void
    {
        $uniq = $this->uniqConEnlaces();
        $preparacion = NormalizacionAlmacenamiento::preparar($uniq);
        file_put_contents(NormalizacionAlmacenamiento::raiz().'/'.$preparacion.'/preparacion/documentos/legajos/l1/seccion-01/a.pdf', 'alterado');

        try {
            NormalizacionAlmacenamiento::verificar($uniq, $preparacion);
            $this->fail('Debió fallar la verificación.');
        } catch (NormalizacionRechazada $rechazo) {
            $this->assertStringContainsString('copia_distinta: 1', $rechazo->getMessage());
        }
        $this->expectException(NormalizacionRechazada::class);
        try {
            NormalizacionAlmacenamiento::cambiar($uniq, $preparacion);
        } finally {
            $this->assertTrue(is_link($this->privado.'/instituciones/uniq/documentos'));
        }
    }

    public function test_the_verification_fails_if_the_inventory_changed_after_preparing(): void
    {
        $uniq = $this->uniqConEnlaces();
        $preparacion = NormalizacionAlmacenamiento::preparar($uniq);
        $this->pdf($uniq, 'legajos/l1/seccion-07/tarde.pdf', $this->archivo('documentos/legajos/l1/seccion-07/tarde.pdf', 'pdf-tarde'));

        $this->expectExceptionMessage('inventario_cambio: 1');
        NormalizacionAlmacenamiento::verificar($uniq, $preparacion);
    }

    public function test_an_interrupted_change_is_reverted_automatically(): void
    {
        $uniq = $this->uniqConEnlaces();
        $antes = InventarioArchivos::de($uniq)['huella'];
        $preparacion = NormalizacionAlmacenamiento::preparar($uniq);
        NormalizacionAlmacenamiento::verificar($uniq, $preparacion);

        try {
            NormalizacionAlmacenamiento::cambiar($uniq, $preparacion, function (string $carpeta): void {
                throw new RuntimeException('Corte simulado tras «'.$carpeta.'»');
            });
            $this->fail('Debió interrumpirse.');
        } catch (NormalizacionRechazada $rechazo) {
            $this->assertStringContainsString('se revirtió', $rechazo->getMessage());
        }

        foreach (['documentos', 'fotos-personal', 'certificados-discapacidad'] as $carpeta) {
            $this->assertTrue(is_link($this->privado.'/instituciones/uniq/'.$carpeta), "«{$carpeta}» vuelve a ser el enlace.");
        }
        $this->assertSame($antes, InventarioArchivos::de($uniq)['huella']);
        $this->assertSame('cambio_revertido', NormalizacionAlmacenamiento::leerEstado(NormalizacionAlmacenamiento::raiz().'/'.$preparacion)['etapa']);
    }

    /** Si el proceso muere a mitad del cambio, revertir lo arregla mirando el disco. */
    public function test_reverting_recovers_a_change_that_died_halfway(): void
    {
        $uniq = $this->uniqConEnlaces();
        $antes = InventarioArchivos::de($uniq)['huella'];
        $preparacion = NormalizacionAlmacenamiento::preparar($uniq);
        $carpeta = NormalizacionAlmacenamiento::raiz().'/'.$preparacion;
        // Como lo dejaría un corte de luz: una carpeta cambiada y otra solo apartada.
        rename($this->privado.'/instituciones/uniq/certificados-discapacidad', $carpeta.'/anteriores/certificados-discapacidad');
        rename($carpeta.'/preparacion/certificados-discapacidad', $this->privado.'/instituciones/uniq/certificados-discapacidad');
        rename($this->privado.'/instituciones/uniq/documentos', $carpeta.'/anteriores/documentos');

        NormalizacionAlmacenamiento::revertir($uniq, $preparacion);

        $this->assertSame($antes, InventarioArchivos::de($uniq)['huella']);
    }

    public function test_modifying_stages_require_subdomain_mode_a_suspended_institution_and_the_slug(): void
    {
        $uniq = $this->uniqConEnlaces(Institucion::ACTIVA);
        $this->artisan('plataforma:normalizar-almacenamiento', ['slug' => 'uniq', '--preparar' => true, '--confirmar' => 'uniq'])
            ->expectsOutputToContain('Suspende la institución')->assertFailed();

        $uniq->update(['estado' => Institucion::SUSPENDIDA]);
        config(['plataforma.modo' => 'unica']);
        foreach (['preparar', 'verificar', 'cambiar', 'revertir'] as $etapa) {
            $this->artisan('plataforma:normalizar-almacenamiento', ['slug' => 'uniq', "--{$etapa}" => true, '--confirmar' => 'uniq'])
                ->expectsOutputToContain('PLATAFORMA_MODO=unica')->assertFailed();
        }

        config(['plataforma.modo' => 'subdominio']);
        $this->artisan('plataforma:normalizar-almacenamiento', ['slug' => 'uniq', '--preparar' => true])
            ->expectsQuestion('Etapa «preparar» para «uniq». Escribe «uniq» para continuar', 'otra')
            ->expectsOutputToContain('No coincide')->assertFailed();
        $this->assertDirectoryDoesNotExist(NormalizacionAlmacenamiento::raiz());
        $this->assertTrue(is_link($this->privado.'/instituciones/uniq/documentos'));
    }

    public function test_an_existing_preparation_is_never_overwritten(): void
    {
        $uniq = $this->uniqConEnlaces();
        Carbon::setTestNow('2026-10-17 10:00:00');
        $ocupada = NormalizacionAlmacenamiento::raiz().'/uniq-20261017-100000';
        File::ensureDirectoryExists($ocupada);
        file_put_contents($ocupada.'/otro.txt', 'no tocar');

        try {
            NormalizacionAlmacenamiento::preparar($uniq);
            $this->fail('Debió rechazar la preparación.');
        } catch (NormalizacionRechazada $rechazo) {
            $this->assertStringContainsString('no se sobrescribe', $rechazo->getMessage());
        }
        $this->assertSame(['otro.txt'], array_values(array_diff(scandir($ocupada), ['.', '..'])));
    }

    public function test_the_command_runs_every_stage_and_audits_only_figures(): void
    {
        $this->uniqConEnlaces();
        foreach (['preparar', 'verificar', 'cambiar'] as $etapa) {
            $this->artisan('plataforma:normalizar-almacenamiento', ['slug' => 'uniq', "--{$etapa}" => true, '--confirmar' => 'uniq'])
                ->expectsOutputToContain("Etapa «{$etapa}» terminada")->doesntExpectOutputToContain(self::DNI)->assertSuccessful();
        }

        $eventos = AuditoriaPlataforma::query()->orderBy('created_at')->get();
        $this->assertEqualsCanonicalizing(['almacenamiento.normalizacion_preparar', 'almacenamiento.normalizacion_verificar', 'almacenamiento.normalizacion_cambiar'], $eventos->pluck('accion')->all());
        $this->assertSame(['certificados-discapacidad', 'documentos', 'fotos-personal'], $eventos->last()->datos_despues['carpetas']);
        $this->assertSame(4, $eventos->last()->datos_despues['archivos']);
        $auditado = $eventos->toJson();
        foreach ([self::DNI, 'legajos/', '.pdf', '.jpg'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $auditado);
        }
    }

    public function test_after_the_change_the_command_gives_the_single_mode_line_and_after_reverting_asks_to_remove_it(): void
    {
        $this->uniqConEnlaces();
        foreach (['preparar', 'verificar'] as $etapa) {
            $this->artisan('plataforma:normalizar-almacenamiento', ['slug' => 'uniq', "--{$etapa}" => true, '--confirmar' => 'uniq'])
                ->doesntExpectOutputToContain('PLATAFORMA_ALMACENAMIENTO_UNICA')->assertSuccessful();
        }
        $this->assertNotNull(AlmacenamientoUnica::motivoCarpeta('instituciones/uniq'));

        $this->artisan('plataforma:normalizar-almacenamiento', ['slug' => 'uniq', '--cambiar' => true, '--confirmar' => 'uniq'])
            ->expectsOutputToContain('Etapa «cambiar» terminada')
            ->expectsOutput('  PLATAFORMA_ALMACENAMIENTO_UNICA=instituciones/uniq')
            ->expectsOutputToContain('No la añadas antes de terminar y comprobar la normalización')
            ->doesntExpectOutputToContain('no añadas PLATAFORMA_ALMACENAMIENTO_UNICA')
            ->doesntExpectOutputToContain(self::DNI)->doesntExpectOutputToContain('PASSWORD')->doesntExpectOutputToContain('APP_KEY')
            ->assertSuccessful();
        $this->assertNull(AlmacenamientoUnica::motivoCarpeta('instituciones/uniq'));

        $this->artisan('plataforma:normalizar-almacenamiento', ['slug' => 'uniq', '--revertir' => true, '--confirmar' => 'uniq'])
            ->expectsOutputToContain('PLATAFORMA_ALMACENAMIENTO_UNICA=instituciones/uniq, quítala de backend/.env')
            ->doesntExpectOutputToContain('No la añadas antes')->assertSuccessful();
        $this->assertNotNull(AlmacenamientoUnica::motivoCarpeta('instituciones/uniq'));
    }
}
