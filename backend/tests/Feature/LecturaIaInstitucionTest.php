<?php

namespace Tests\Feature;

use App\Jobs\LeerDocumentoConIa;
use App\Models\AuditoriaEvento;
use App\Models\LecturaIa;
use App\Models\Plataforma\Institucion;
use App\Models\Usuario;
use App\Support\Documentos\EscanerSecciones;
use App\Support\Plataforma\ConexionInstitucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;

/**
 * Con varias instituciones, la lectura con IA corre después de responder, cuando ResolverInstitucion ya salió del
 * contexto de la institución: el trabajo debe volver a entrar en él y dejar la lectura y su resultado en la base y la
 * caché de esa institución, no en las de la plataforma.
 */
class LecturaIaInstitucionTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    /** @var list<string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['plataforma.modo' => 'subdominio', 'cache.default' => 'file', 'escaner.modelo' => 'modelo-prueba']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            is_dir($ruta) ? File::deleteDirectory($ruta) : File::delete($ruta);
        }
        parent::tearDown();
    }

    public function test_la_lectura_despues_de_responder_vuelve_a_la_base_y_la_cache_de_su_institucion(): void
    {
        $this->temporales[] = $base = tempnam(sys_get_temp_dir(), 'inst_');
        $this->temporales[] = storage_path('framework/cache/instituciones/uno');
        $uno = $this->institucion(['slug' => 'uno', 'estado' => Institucion::ACTIVA, 'base_datos' => $base, 'almacenamiento' => 'instituciones/uno', 'aprovisionada_en' => now()]);
        $this->temporales[] = $pdf = tempnam(sys_get_temp_dir(), 'pdf_');

        // Como en la petición: la lectura y el trabajo se crean con la institución activa.
        [$lectura, $trabajo] = ConexionInstitucion::usar($uno, function () use ($pdf): array {
            Artisan::call('migrate', ['--force' => true]);
            // Quien escanea y la persona del legajo existen en la base de la institución.
            $usuario = Usuario::factory()->create();
            $persona = Usuario::factory()->create();
            // El trabajo corre al final de la misma petición que lo creó: su usuario es quien escaneó (auditoría).
            $this->app['request']->setUserResolver(fn () => $usuario);
            $lectura = LecturaIa::query()->create([
                'usuario_id' => $persona->id, 'tipo_documento_id' => (string) Str::uuid(), 'registrado_por' => $usuario->id,
                'modelo' => 'modelo-prueba', 'estado' => 'en_espera', 'campos_pedidos' => ['emisor'], 'campos_propuestos' => [], 'campos_sugeridos' => [],
            ]);

            return [$lectura, new LeerDocumentoConIa($lectura->id, $pdf, 'Constancia', ['emisor'], ['dni' => '45678912', 'nombres' => 'ANA', 'apellido_paterno' => 'TORRES', 'apellido_materno' => 'QUISPE'])];
        });
        $this->assertSame($uno->id, $trabajo->institucionId);

        // Como después de responder: fuera de la institución.
        $escaner = Mockery::mock(EscanerSecciones::class);
        $escaner->shouldReceive('leer')->once()->andReturn('DNI 45678912');
        $escaner->shouldReceive('interpretar')->once()->andReturn(['emisor' => ['valor' => 'UNIVERSIDAD', 'evidencia' => 'UNIVERSIDAD']]);
        $trabajo->handle($escaner);

        $this->assertNull(Cache::get($lectura->claveResultado()), 'El resultado no queda en la caché de la plataforma.');
        $this->assertSame(0, LecturaIa::query()->count(), 'Ni la lectura en la base de la plataforma.');
        ConexionInstitucion::usar($uno, function () use ($lectura): void {
            $this->assertSame('lista', LecturaIa::query()->findOrFail($lectura->id)->estado);
            $this->assertIsString(Cache::get($lectura->claveResultado()));
            $this->assertSame(1, AuditoriaEvento::query()->where('accion', 'documento.lectura_ia')->count(), 'La auditoría, también en su base.');
        });
        $this->assertFileDoesNotExist($pdf, 'La copia temporal del PDF se borra.');
    }

    public function test_sin_varias_instituciones_corre_en_la_base_de_siempre(): void
    {
        config(['plataforma.modo' => 'unica']);
        $trabajo = new LeerDocumentoConIa((string) Str::uuid(), '/tmp/no-existe.pdf', 'Constancia', ['emisor'], []);

        $this->assertNull($trabajo->institucionId);
    }
}
