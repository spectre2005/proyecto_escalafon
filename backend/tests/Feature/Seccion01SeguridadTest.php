<?php

namespace Tests\Feature;

use App\Models\BitacoraAccesoLegajo;
use App\Models\CertificadoDiscapacidad;
use App\Models\DeclaracionVerificacion;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\SeccionLegajo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Sección 01: datos sensibles en el CRUD genérico, bitácora de descargas y conteos del directorio. */
class Seccion01SeguridadTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";

    /** Administra el sistema y ve legajos, pero su rol no tiene ve_datos_sensibles. */
    private function actuarSinSensibles(): Usuario
    {
        config(['permisos.roles.admin_sin_sensibles' => ['sistema.administrar', 'legajo.ver', 'personal.ver']]);
        $usuario = $this->asignarRol(Usuario::factory()->create(), 'admin_sin_sensibles', [], ['ve_datos_sensibles' => false])->refresh();
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    public function test_generic_crud_of_section_01_requires_sensitive_permission_and_never_deletes(): void
    {
        $persona = Usuario::factory()->create();
        $registros = [
            'familiares' => Familiar::factory()->create(['usuario_id' => $persona->id, 'parentesco' => 'hijo']),
            'declaraciones-verificaciones' => DeclaracionVerificacion::factory()->create(['usuario_id' => $persona->id]),
            'certificados-discapacidad' => CertificadoDiscapacidad::factory()->create(['usuario_id' => $persona->id]),
        ];

        $this->actuarSinSensibles();
        foreach ($registros as $recurso => $registro) {
            $this->getJson('/api/v1/'.$recurso)->assertForbidden();
            $this->getJson('/api/v1/'.$recurso.'/'.$registro->id)->assertForbidden();
            $this->postJson('/api/v1/'.$recurso, [])->assertForbidden();
            $this->patchJson('/api/v1/'.$recurso.'/'.$registro->id, ['anulado' => true, 'motivo_anulacion' => 'Sin permiso'])->assertForbidden();
            $this->deleteJson('/api/v1/'.$recurso.'/'.$registro->id)->assertForbidden();
        }
        // Los demás recursos del CRUD genérico no cambian.
        $this->getJson('/api/v1/capacitaciones')->assertOk();

        $this->actuarComo();
        foreach ($registros as $recurso => $registro) {
            $this->getJson('/api/v1/'.$recurso)->assertOk();
            $this->getJson('/api/v1/'.$recurso.'/'.$registro->id)->assertOk();
            // No se eliminan: se anulan con motivo.
            $this->deleteJson('/api/v1/'.$recurso.'/'.$registro->id)->assertStatus(405);
            $this->assertModelExists($registro);
        }
    }

    public function test_downloading_a_section_01_pdf_writes_the_access_log(): void
    {
        Storage::fake('local');
        $admin = $this->actuarComo();
        $persona = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'pasivo', 'fecha_apertura' => now()->subYears(3)]);
        $activo = Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'activo', 'fecha_apertura' => now()->subYear()]);

        foreach ([
            'declaraciones-verificaciones' => ['tipo' => 'antecedente_penal', 'fecha' => '2025-01-10', 'resultado' => 'sin_registros'],
            'certificados-discapacidad' => ['persona' => 'servidor', 'numero_conadis' => 'C-123'],
        ] as $tipo => $data) {
            $url = '/api/v1/expedientes/'.$persona->id.'/'.$tipo;
            $id = $this->post($url, $data + ['pdf' => UploadedFile::fake()->createWithContent('registro.pdf', self::PDF)], ['Accept' => 'application/json'])
                ->assertCreated()->json('data.id');
            $this->assertSame(0, BitacoraAccesoLegajo::query()->count());

            $this->withHeader('User-Agent', 'Navegador de prueba')->get($url.'/'.$id.'/pdf')->assertOk()->assertDownload('registro.pdf');

            $this->assertDatabaseHas('bitacora_accesos_legajo', [
                'legajo_id' => $activo->id, 'documento_id' => null, 'usuario_id' => $admin->id,
                'accion' => 'descargar', 'ip_origen' => '127.0.0.1', 'user_agent' => 'Navegador de prueba',
            ]);
            $this->assertNotNull(BitacoraAccesoLegajo::query()->latest('fecha_acceso')->value('fecha_acceso'));
            BitacoraAccesoLegajo::query()->delete();
        }
    }

    public function test_pdf_is_not_delivered_when_the_access_cannot_be_logged(): void
    {
        Storage::fake('local');
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $url = '/api/v1/expedientes/'.$persona->id.'/certificados-discapacidad';
        $id = $this->post($url, ['persona' => 'servidor', 'numero_conadis' => 'C-1', 'pdf' => UploadedFile::fake()->createWithContent('c.pdf', self::PDF)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $this->getJson($url.'/'.$id.'/pdf')->assertConflict();
        $this->assertSame(0, BitacoraAccesoLegajo::query()->count());
    }

    public function test_directory_count_and_document_states_exclude_sensitive_sections_without_permission(): void
    {
        $persona = Usuario::factory()->create(['nombres' => 'Persona', 'apellido_paterno' => 'Contada']);
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id]);
        foreach ([1 => 'Información personal y familiar', 3 => 'Formación académica y capacitación', 9 => 'Relaciones laborales', 10 => 'SST y bienestar'] as $id => $nombre) {
            SeccionLegajo::factory()->create(['id' => $id, 'nombre' => $nombre]);
        }
        foreach ([1 => 'observado', 9 => 'verificado', 10 => 'falso', 3 => 'cargado'] as $seccion => $estado) {
            DocumentoUsuario::factory()->create(['usuario_id' => $persona->id, 'legajo_id' => $legajo->id, 'seccion_legajo_id' => $seccion, 'estado' => $estado]);
        }
        $conteo = fn (): ?int => collect($this->getJson('/api/v1/expedientes?q=Contada')->assertOk()->json('data'))
            ->firstWhere('id', $persona->id)['documentos_usuario_por_usuario_count'] ?? null;

        $this->actuarSinSensibles();
        $this->assertSame(1, $conteo());
        $this->getJson('/api/v1/expedientes/'.$persona->id)->assertOk()->assertJsonPath('data.document_states', ['cargado']);

        $this->actuarComo();
        $this->assertSame(4, $conteo());
        $this->getJson('/api/v1/expedientes/'.$persona->id)->assertOk()->assertJsonPath('data.document_states', ['cargado', 'falso', 'observado', 'verificado']);
    }
}
