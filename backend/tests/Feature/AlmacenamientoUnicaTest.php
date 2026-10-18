<?php

namespace Tests\Feature;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\AlmacenamientoUnica;
use App\Support\Plataforma\AlmacenamientoUnicaNoValido;
use App\Support\Plataforma\ConexionInstitucion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/**
 * PLATAFORMA_ALMACENAMIENTO_UNICA (AlmacenamientoUnica), en el storage temporal de PHPUnit (tests/bootstrap.php) y con
 * la plataforma en SQLite en memoria: ningún archivo ni base real.
 *
 * «uniq» es la institución de la base de las pruebas (su base_datos es la de la conexión por defecto); «otra» tiene su
 * propia base. Las carpetas comunes (documentos, fotos-personal) tienen un archivo cada una que nunca debe cambiar.
 */
class AlmacenamientoUnicaTest extends TestCase
{
    use UsaPlataforma;

    private string $privado;

    /** @var array<string, string> disco => raíz antes de cada prueba */
    private array $raices;

    /** @var array<string, array<string, mixed>> */
    private array $discos;

    protected function setUp(): void
    {
        parent::setUp();
        // Nunca el storage real: el de cada ejecución de PHPUnit.
        if (! str_starts_with(storage_path(), sys_get_temp_dir().'/escalafon-pruebas-')) {
            throw new RuntimeException('Estas pruebas solo corren en el storage temporal de PHPUnit.');
        }
        $this->privado = storage_path('app/private');
        $this->discos = config('filesystems.disks');
        $this->raices = collect($this->discos)->map(fn (array $disco): ?string => $disco['root'] ?? null)->all();
        config(['plataforma.modo' => 'unica']);
        $this->archivo('documentos/comun.pdf', 'pdf común');
        $this->archivo('fotos-personal/comun.jpg', 'foto común');
    }

    protected function tearDown(): void
    {
        // Primero las carpetas con enlaces (deleteDirectory borra el enlace, no su destino).
        foreach (['instituciones', 'documentos', 'fotos-personal', 'certificados-discapacidad', 'fuera'] as $carpeta) {
            File::deleteDirectory($this->privado.'/'.$carpeta);
        }
        parent::tearDown();
    }

    private function archivo(string $relativa, string $contenido): void
    {
        File::ensureDirectoryExists(dirname($this->privado.'/'.$relativa));
        file_put_contents($this->privado.'/'.$relativa, $contenido);
    }

    /** Institución aprovisionada con su carpeta real (y sus carpetas de discos, como tras normalizar). */
    private function conCarpeta(string $slug, ?string $base = null): Institucion
    {
        foreach (['documentos', 'fotos-personal'] as $carpeta) {
            File::ensureDirectoryExists($this->privado.'/instituciones/'.$slug.'/'.$carpeta);
        }

        return $this->institucion(['slug' => $slug, 'estado' => Institucion::ACTIVA, 'aprovisionada_en' => now(),
            'base_datos' => $base ?? 'escalafon_'.$slug]);
    }

    private function uniq(): Institucion
    {
        return $this->conCarpeta('uniq', (string) config('database.connections.'.config('database.default').'.database'));
    }

    /** Lista de archivos (ruta relativa => contenido) de las carpetas comunes y de las de otras instituciones. */
    private function ajenos(string $excepto = 'instituciones/uniq'): array
    {
        $archivos = [];
        foreach (File::allFiles($this->privado) as $archivo) {
            $relativa = substr($archivo->getPathname(), strlen($this->privado) + 1);
            if (! str_starts_with($relativa, $excepto.'/')) {
                $archivos[$relativa] = $archivo->getContents();
            }
        }
        ksort($archivos);

        return $archivos;
    }

    private function rechazo(string $valor, string $mensaje): void
    {
        config(['plataforma.almacenamiento_unica' => $valor]);
        $antes = $this->ajenos('-');
        try {
            AlmacenamientoUnica::aplicar();
            $this->fail('Se aceptó '.$valor);
        } catch (AlmacenamientoUnicaNoValido $error) {
            $this->assertStringContainsString($mensaje, $error->getMessage());
        }
        // Discos privados inutilizables: ni la carpeta común ni otra institución reciben nada.
        foreach (['documentos', 'personal_photos', 'local'] as $disco) {
            try {
                Storage::disk($disco)->put('intento.pdf', 'no debe escribirse');
                $this->fail("El disco {$disco} siguió escribiendo.");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame($antes, $this->ajenos('-'));
        $this->assertSame($this->raices['public'], config('filesystems.disks.public.root'));
    }

    public function test_modo_subdominio_ignora_la_variable_y_conexion_institucion_no_cambia(): void
    {
        config(['plataforma.modo' => 'subdominio', 'plataforma.almacenamiento_unica' => 'instituciones/no-existe',
            'database.connections.plataforma.database' => $this->privado.'/no-existe/plataforma.sqlite']);
        DB::purge('plataforma');

        AlmacenamientoUnica::aplicar();

        $this->assertSame($this->raices, collect(config('filesystems.disks'))->map(fn (array $d): ?string => $d['root'] ?? null)->all());
        $this->assertNull(config('plataforma.raices_servidor'));
        $otra = new Institucion(['slug' => 'otra', 'almacenamiento' => 'instituciones/otra', 'base_datos' => 'escalafon_otra']);
        $antes = ConexionInstitucion::entrar($otra);
        try {
            $this->assertSame($this->privado.'/instituciones/otra/documentos', config('filesystems.disks.documentos.root'));
            $this->assertSame($this->privado.'/instituciones/otra', config('filesystems.disks.local.root'));
            $this->assertSame($this->raices['public'], config('filesystems.disks.public.root'));
        } finally {
            ConexionInstitucion::salir($antes);
        }
        $this->assertSame($this->raices['documentos'], config('filesystems.disks.documentos.root'));
    }

    public function test_modo_unica_vacia_conserva_las_carpetas_comunes_sin_consultar_la_plataforma(): void
    {
        foreach (['', '   ', null] as $valor) {
            // Si consultara la plataforma, fallaría: la base no existe.
            config(['plataforma.almacenamiento_unica' => $valor, 'database.connections.plataforma.database' => $this->privado.'/no-existe/plataforma.sqlite']);
            DB::purge('plataforma');

            AlmacenamientoUnica::aplicar();

            $this->assertSame($this->raices, collect(config('filesystems.disks'))->map(fn (array $d): ?string => $d['root'] ?? null)->all());
        }
        Storage::disk('documentos')->put('nuevo.pdf', 'como siempre');
        $this->assertFileExists($this->privado.'/documentos/nuevo.pdf');
        $this->assertDirectoryDoesNotExist($this->privado.'/instituciones');
    }

    public function test_modo_unica_con_carpeta_propia_reubica_solo_los_discos_privados(): void
    {
        $this->uniq();
        $this->conCarpeta('otra');
        config(['plataforma.almacenamiento_unica' => 'instituciones/uniq']);
        $ajenos = $this->ajenos();

        AlmacenamientoUnica::aplicar();
        AlmacenamientoUnica::aplicar(); // con config:cache se vuelve a aplicar sobre lo ya reubicado

        $propia = $this->privado.'/instituciones/uniq';
        $this->assertSame($propia.'/documentos', config('filesystems.disks.documentos.root'));
        $this->assertSame($propia.'/fotos-personal', config('filesystems.disks.personal_photos.root'));
        $this->assertSame($propia, config('filesystems.disks.local.root'));
        $this->assertSame($this->raices['public'], config('filesystems.disks.public.root'));
        $this->assertSame('s3', config('filesystems.disks.s3.driver'));
        $this->assertEquals(['personal_photos' => $this->raices['personal_photos'], 'documentos' => $this->raices['documentos'], 'local' => $this->raices['local']],
            config('plataforma.raices_servidor'));
        // Base, caché y sesiones no cambian; no «entra» en la institución.
        $this->assertNotSame(ConexionInstitucion::CONEXION, config('database.default'));
        $this->assertFalse(app()->bound(Institucion::class));

        Storage::disk('documentos')->put('1/nuevo.pdf', 'propio');
        Storage::disk('personal_photos')->put('foto.jpg', 'propia');
        Storage::disk('local')->put('certificados-discapacidad/c.pdf', 'propio');
        $this->assertFileExists($propia.'/documentos/1/nuevo.pdf');
        $this->assertFileExists($propia.'/fotos-personal/foto.jpg');
        $this->assertFileExists($propia.'/certificados-discapacidad/c.pdf');
        $this->assertFalse(Storage::disk('documentos')->exists('comun.pdf'));
        $this->assertSame($ajenos, $this->ajenos());

        // Entrar en otra institución parte de las raíces del servidor, no de las ya reubicadas.
        $antes = ConexionInstitucion::entrar(Institucion::query()->where('slug', 'otra')->firstOrFail());
        try {
            $this->assertSame($this->privado.'/instituciones/otra/documentos', config('filesystems.disks.documentos.root'));
        } finally {
            ConexionInstitucion::salir($antes);
        }
        $this->assertSame($propia.'/documentos', config('filesystems.disks.documentos.root'));
        // El inventario (InventarioArchivos) sigue viendo las carpetas comunes como comunes.
        $this->assertEquals(['documentos' => 'documentos', 'personal_photos' => 'fotos-personal', 'local' => ''],
            collect(ConexionInstitucion::discosPrivados())->map(fn (string $r): string => trim(substr($r, strlen($this->privado)), '/'))->all());
    }

    public function test_rechaza_rutas_fuera_del_almacenamiento_permitido(): void
    {
        $this->uniq();
        File::ensureDirectoryExists($this->privado.'/fuera');
        foreach (['../documentos', '/etc', 'documentos', 'instituciones', 'instituciones/', 'instituciones/uniq/', 'instituciones/uniq/documentos',
            'instituciones/../fuera', 'instituciones/uniq/../otra', 'instituciones/UNIQ', 'fuera', 'instituciones/uniq/.', 'instituciones/uniq otra'] as $valor) {
            config(['filesystems.disks' => $this->discos]);
            $this->rechazo($valor, 'no es una carpeta institucional válida');
        }
    }

    /** uniq registrada en la plataforma, sin crear su carpeta. */
    private function uniqRegistrada(): void
    {
        $this->institucion(['slug' => 'uniq', 'estado' => Institucion::ACTIVA, 'aprovisionada_en' => now(),
            'base_datos' => (string) config('database.connections.'.config('database.default').'.database')]);
    }

    public function test_rechaza_la_carpeta_de_otra_institucion(): void
    {
        $this->uniq();
        $this->conCarpeta('otra');
        $this->archivo('instituciones/otra/documentos/de-otra.pdf', 'de otra');

        $this->rechazo('instituciones/otra', 'es de la institución otra, y su base no es la de esta instalación');
        $this->assertFileExists($this->privado.'/instituciones/otra/documentos/de-otra.pdf');
    }

    public function test_rechaza_una_configuracion_que_no_corresponde_a_la_base(): void
    {
        // Carpeta sin institución registrada.
        File::ensureDirectoryExists($this->privado.'/instituciones/huerfana/documentos');
        $this->rechazo('instituciones/huerfana', 'ninguna institución de la plataforma usa la carpeta');
    }

    public function test_rechaza_una_institucion_sin_aprovisionar(): void
    {
        $this->uniq()->update(['aprovisionada_en' => null, 'estado' => Institucion::REGISTRADA]);
        $this->rechazo('instituciones/uniq', 'no está aprovisionada');
    }

    public function test_rechaza_si_la_base_de_la_instalacion_no_tiene_nombre(): void
    {
        $this->conCarpeta('uniq', '');
        config(['database.connections.'.config('database.default').'.database' => '']);
        $this->rechazo('instituciones/uniq', 'su base no es la de esta instalación');
    }

    public function test_rechaza_si_no_puede_leer_la_plataforma(): void
    {
        $this->uniq();
        config(['database.connections.plataforma.database' => $this->privado.'/no-existe/plataforma.sqlite']);
        DB::purge('plataforma');
        $this->rechazo('instituciones/uniq', 'no se pudo leer la base de la plataforma');
    }

    public function test_rechaza_una_carpeta_inexistente(): void
    {
        $this->uniqRegistrada();
        $this->rechazo('instituciones/uniq', 'no existe o no es una carpeta real');
    }

    public function test_rechaza_la_carpeta_con_enlaces_a_carpetas_comunes_como_uniq_hoy(): void
    {
        $this->uniqRegistrada();
        File::ensureDirectoryExists($this->privado.'/instituciones/uniq');
        File::ensureDirectoryExists($this->privado.'/certificados-discapacidad');
        foreach (['documentos', 'fotos-personal', 'certificados-discapacidad'] as $carpeta) {
            symlink($this->privado.'/'.$carpeta, $this->privado.'/instituciones/uniq/'.$carpeta);
        }

        $this->rechazo('instituciones/uniq', 'tiene enlaces simbólicos');
        $this->assertSame(['documentos/comun.pdf', 'fotos-personal/comun.jpg'], array_keys($this->ajenos('-')));
    }

    public function test_rechaza_la_carpeta_que_es_un_enlace_a_otra_institucion(): void
    {
        $this->uniqRegistrada();
        $this->conCarpeta('otra');
        symlink($this->privado.'/instituciones/otra', $this->privado.'/instituciones/uniq');

        $this->rechazo('instituciones/uniq', 'no existe o no es una carpeta real');
    }

    public function test_rechaza_una_carpeta_de_disco_que_sale_de_la_institucion(): void
    {
        $this->uniq();
        // Un enlace más abajo del primer nivel no se revisa aquí, pero la raíz de un disco que sale sí.
        config(['filesystems.disks.extra' => ['driver' => 'local', 'root' => $this->privado.'/fuera/extra']]);
        File::ensureDirectoryExists($this->privado.'/fuera/extra');
        File::ensureDirectoryExists($this->privado.'/instituciones/uniq/fuera');
        symlink($this->privado.'/fuera/extra', $this->privado.'/instituciones/uniq/fuera/extra');

        $this->rechazo('instituciones/uniq', 'un disco saldría de la carpeta');
        $this->assertSame([], File::files($this->privado.'/fuera/extra'));
    }

    public function test_motivo_carpeta_solo_mira_la_carpeta_sin_consultar_la_plataforma(): void
    {
        config(['database.connections.plataforma.database' => $this->privado.'/no-existe/plataforma.sqlite']);
        DB::purge('plataforma');
        File::ensureDirectoryExists($this->privado.'/instituciones/uniq/documentos');

        $this->assertNull(AlmacenamientoUnica::motivoCarpeta('instituciones/uniq'));
        $this->assertStringContainsString('no es una carpeta institucional válida', (string) AlmacenamientoUnica::motivoCarpeta('../documentos'));
        $this->assertStringContainsString('no existe o no es una carpeta real', (string) AlmacenamientoUnica::motivoCarpeta('instituciones/nadie'));
        symlink('../../fotos-personal', $this->privado.'/instituciones/uniq/fotos-personal');
        $this->assertStringContainsString('tiene enlaces simbólicos', (string) AlmacenamientoUnica::motivoCarpeta('instituciones/uniq'));
        // Solo consulta: los discos no cambian.
        $this->assertSame($this->raices['documentos'], config('filesystems.disks.documentos.root'));
    }
}
