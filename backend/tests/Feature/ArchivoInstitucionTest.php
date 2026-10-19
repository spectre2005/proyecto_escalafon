<?php

namespace Tests\Feature;

use App\Models\Plataforma\AuditoriaPlataforma;
use App\Models\Plataforma\Institucion;
use App\Models\Usuario;
use App\Support\Plataforma\ConexionInstitucion;
use App\Support\Plataforma\ExportacionInstitucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UsaPlataforma;
use Tests\TestCase;
use ZipArchive;

class ArchivoInstitucionTest extends TestCase
{
    use RefreshDatabase;
    use UsaPlataforma;

    public function test_archive_requires_suspension_blocks_access_and_preserves_data(): void
    {
        $this->actingAs($this->administrador(), 'plataforma');
        $institucion = $this->institucion(['estado' => Institucion::ACTIVA, 'aprovisionada_en' => now(), 'base_datos' => ':memory:']);
        $persona = Usuario::factory()->create();
        $carpeta = storage_path('app/private/'.$institucion->almacenamiento);
        File::ensureDirectoryExists($carpeta);
        File::put($carpeta.'/conservar.pdf', 'PDF ficticio para conservar');
        $this->postJson('/api/plataforma/instituciones/'.$institucion->id.'/archivar', ['motivo' => 'Fin del servicio contratado'])->assertConflict();
        $this->postJson('/api/plataforma/instituciones/'.$institucion->id.'/suspender', ['motivo' => 'Fin del servicio contratado'])->assertOk();
        $this->postJson('/api/plataforma/instituciones/'.$institucion->id.'/archivar', ['motivo' => 'Conservar los datos al terminar contrato'])->assertOk()->assertJsonPath('data.estado', 'archivada');
        $this->postJson('/api/plataforma/instituciones/'.$institucion->id.'/activar')->assertConflict();
        $this->assertFalse($institucion->refresh()->operativa());
        $this->assertSame(1, AuditoriaPlataforma::query()->where('accion', 'institucion.archivada')->count());
        $this->getJson('/api/v1/institucion')->assertForbidden()->assertJsonPath('codigo', 'institucion_archivada');
        $this->assertDatabaseHas('usuarios', ['id' => $persona->id]);
        $this->assertSame('PDF ficticio para conservar', File::get($carpeta.'/conservar.pdf'));
        $this->getJson('/api/plataforma/instituciones/'.$institucion->id)->assertOk();
    }

    public function test_export_contains_only_the_selected_database_and_private_files_without_external_keys(): void
    {
        $base = storage_path('framework/tenant-exportacion.sqlite');
        File::put($base, '');
        $institucion = $this->institucion(['estado' => Institucion::SUSPENDIDA, 'aprovisionada_en' => now(), 'base_datos' => $base]);
        $ruta = null;
        try {
            ConexionInstitucion::usar($institucion, function (): void {
                Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
                Usuario::factory()->create(['nombres' => 'Persona exportada']);
                Storage::disk(config('legajo.disco_documentos'))->put('prueba.pdf', 'contenido protegido de ejemplo');
            });
            File::ensureDirectoryExists(storage_path('app/keys'), 0700);
            File::put(storage_path('app/keys/no-exportar.key'), 'clave externa ficticia');
            $exportacion = ExportacionInstitucion::generar($institucion);
            $ruta = $exportacion['ruta'];
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($ruta));
            $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($institucion->id, $manifest['institucion']['id']);
            $this->assertSame(1, $manifest['tablas']['usuarios']['filas']);
            $this->assertArrayNotHasKey('administradores_plataforma', $manifest['tablas']);
            $personas = json_decode($zip->getFromName('datos/usuarios.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('Persona exportada', $personas[0]['nombres']);
            $this->assertSame('contenido protegido de ejemplo', $zip->getFromName('archivos/documentos/prueba.pdf'));
            $this->assertFalse($zip->getFromName('archivos/keys/no-exportar.key'));
            $this->assertSame(hash('sha256', 'contenido protegido de ejemplo'), $manifest['archivos']['documentos/prueba.pdf']['sha256']);
            $zip->close();
        } finally {
            if ($ruta) {
                File::delete($ruta);
            }
            File::delete($base);
        }
    }

    public function test_institutional_session_cannot_archive_or_export_platform_data(): void
    {
        $this->actuarComo();
        $institucion = $this->institucion();
        $this->postJson('/api/plataforma/instituciones/'.$institucion->id.'/exportar')->assertUnauthorized();
        $this->postJson('/api/plataforma/instituciones/'.$institucion->id.'/archivar', ['motivo' => 'No autorizado'])->assertUnauthorized();
    }
}
