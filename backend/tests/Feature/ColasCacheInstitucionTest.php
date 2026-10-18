<?php

namespace Tests\Feature;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\ColasInstitucion;
use App\Support\Plataforma\ConexionInstitucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\UsaPlataforma;
use Tests\Fixtures\TrabajoQueAnotaSuEntorno;
use Tests\TestCase;

/**
 * Caché y colas con varias instituciones (PLATAFORMA_MODO=subdominio): cada institución tiene su caché (también el
 * límite de intentos de login) y un trabajo encolado se ejecuta con los recursos de su institución, guardado en la base
 * de la plataforma. Si su institución ya no está activa, falla sin ejecutarse.
 */
class ColasCacheInstitucionTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    /** @var list<string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['plataforma.modo' => 'subdominio', 'queue.default' => 'database', 'cache.default' => 'file', 'cache.limiter' => null]);
        ColasInstitucion::configurar();
        TrabajoQueAnotaSuEntorno::$ejecuciones = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            is_dir($ruta) ? File::deleteDirectory($ruta) : File::delete($ruta);
        }
        parent::tearDown();
    }

    /** Institución con una base SQLite vacía: basta para saber con qué base corre cada cosa. */
    private function con(string $slug, string $estado = Institucion::ACTIVA): Institucion
    {
        $this->temporales[] = $base = tempnam(sys_get_temp_dir(), 'inst_');
        $this->temporales[] = storage_path('framework/cache/instituciones/'.$slug);

        return $this->institucion(['slug' => $slug, 'estado' => $estado, 'base_datos' => $base, 'almacenamiento' => 'instituciones/'.$slug, 'aprovisionada_en' => now()]);
    }

    private function trabajar(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true])->assertSuccessful();
    }

    public function test_each_institution_has_its_own_cache_and_login_attempts(): void
    {
        $uno = $this->con('uno');
        $dos = $this->con('dos');

        ConexionInstitucion::usar($uno, function (): void {
            Cache::put('clave', 'de uno', 60);
            RateLimiter::hit('login:ana@example.test|127.0.0.1', 60);
            RateLimiter::hit('login:ana@example.test|127.0.0.1', 60);
        });

        $this->assertSame('de uno', ConexionInstitucion::usar($uno, fn () => Cache::get('clave')));
        $this->assertSame(2, ConexionInstitucion::usar($uno, fn () => RateLimiter::attempts('login:ana@example.test|127.0.0.1')));
        $this->assertNull(ConexionInstitucion::usar($dos, fn () => Cache::get('clave')));
        $this->assertSame(0, ConexionInstitucion::usar($dos, fn () => RateLimiter::attempts('login:ana@example.test|127.0.0.1')));
        $this->assertNull(Cache::get('clave'), 'Fuera de una institución tampoco se ve.');
        $this->assertDirectoryExists(storage_path('framework/cache/instituciones/uno'));
    }

    public function test_a_queued_job_runs_with_the_resources_of_its_institution(): void
    {
        $uno = $this->con('uno');
        // dispatch() encola al destruirse su resultado: dentro del cuerpo, para que sea con la institución activa.
        ConexionInstitucion::usar($uno, function (): void {
            TrabajoQueAnotaSuEntorno::dispatch();
        });
        TrabajoQueAnotaSuEntorno::dispatch();

        // Los trabajos quedan en la base de la plataforma, con su institución (o sin ella).
        $payloads = DB::connection('plataforma')->table('jobs')->orderBy('id')->pluck('payload')->map(fn (string $p) => json_decode($p, true));
        $this->assertSame($uno->id, $payloads[0]['institucion_id']);
        $this->assertArrayNotHasKey('institucion_id', $payloads[1]);

        $this->trabajar();
        $this->trabajar();

        $this->assertSame($uno->base_datos, TrabajoQueAnotaSuEntorno::$ejecuciones[0]['base']);
        $this->assertSame(storage_path('app/private/instituciones/uno/documentos').DIRECTORY_SEPARATOR, TrabajoQueAnotaSuEntorno::$ejecuciones[0]['documentos']);
        $this->assertStringEndsWith('uno-', TrabajoQueAnotaSuEntorno::$ejecuciones[0]['cache']);
        $this->assertSame(':memory:', TrabajoQueAnotaSuEntorno::$ejecuciones[1]['base'], 'Sin institución: la base del .env.');
        $this->assertSame('sqlite', config('database.default'), 'El worker vuelve a la configuración del .env.');
    }

    public function test_a_job_of_an_institution_no_longer_active_fails_without_running(): void
    {
        $uno = $this->con('uno');
        // dispatch() encola al destruirse su resultado: dentro del cuerpo, para que sea con la institución activa.
        ConexionInstitucion::usar($uno, function (): void {
            TrabajoQueAnotaSuEntorno::dispatch();
        });
        $uno->update(['estado' => Institucion::SUSPENDIDA]);

        $this->trabajar();

        $this->assertSame([], TrabajoQueAnotaSuEntorno::$ejecuciones);
        $this->assertSame(0, DB::connection('plataforma')->table('jobs')->count());
        $this->assertStringContainsString('no está activa', DB::connection('plataforma')->table('failed_jobs')->value('exception'));
    }

    public function test_a_job_of_an_institution_in_an_unknown_state_fails_without_running(): void
    {
        $uno = $this->con('uno');
        ConexionInstitucion::usar($uno, function (): void {
            TrabajoQueAnotaSuEntorno::dispatch();
        });
        $uno->update(['estado' => 'archivada']);

        $this->trabajar();

        $this->assertSame([], TrabajoQueAnotaSuEntorno::$ejecuciones);
        $this->assertSame(1, DB::connection('plataforma')->table('failed_jobs')->count());
    }
}
