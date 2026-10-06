<?php

namespace Tests\Feature;

use App\Models\CertificadoDiscapacidad;
use App\Models\DeclaracionVerificacion;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\SeccionLegajo;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Seccion01ApiTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";

    /** PDF guardado antes del sistema de folios: solo pdf_path y pdf_nombre, sin documento del legajo. */
    private function pdfAnterior(Model $registro, string $nombre, string $contenido = self::PDF): string
    {
        $path = $registro->getTable().'/'.$registro->usuario_id.'/'.$nombre;
        Storage::disk('local')->put($path, $contenido);
        DB::table($registro->getTable())->where('id', $registro->id)->update(['pdf_path' => $path, 'pdf_nombre' => $nombre]);

        return $path;
    }

    private function url(Usuario $persona, string $tipo): string
    {
        return '/api/v1/expedientes/'.$persona->id.'/'.$tipo;
    }

    public function test_declarations_are_registered_with_closed_lists_and_server_author(): void
    {
        $autor = $this->actuarComo();
        $persona = Usuario::factory()->create();
        $url = $this->url($persona, 'declaraciones-verificaciones');
        $valida = ['tipo' => 'antecedente_penal', 'fecha' => '2025-03-10', 'resultado' => 'sin_registros', 'vigente_hasta' => '2025-06-10', 'observacion' => 'Certificado en línea'];

        $this->postJson($url, $valida)->assertCreated()
            ->assertJsonPath('data.usuario_id', $persona->id)->assertJsonPath('data.registrado_por', $autor->id)->assertJsonPath('data.anulado', false);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.values.tipo', 'antecedente_penal');

        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['tipo', 'fecha', 'resultado']);
        $this->postJson($url, ['tipo' => 'antecedente_deportivo'] + $valida)->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->postJson($url, ['resultado' => 'aprobado'] + $valida)->assertUnprocessable()->assertJsonValidationErrors('resultado');
        $this->postJson($url, ['fecha' => now()->addDay()->toDateString()] + $valida)->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->postJson($url, ['vigente_hasta' => '2025-01-01'] + $valida)->assertUnprocessable()->assertJsonValidationErrors('vigente_hasta');
        $this->postJson($url, $valida + ['registrado_por' => $persona->id, 'anulado' => true])->assertCreated()
            ->assertJsonPath('data.registrado_por', $autor->id)->assertJsonPath('data.anulado', false);
        $this->postJson('/api/v1/expedientes/00000000-0000-4000-8000-000000000000/declaraciones-verificaciones', $valida)->assertNotFound();
    }

    public function test_certificates_belong_to_the_server_or_to_one_of_their_current_relatives(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $hijo = Familiar::factory()->create(['usuario_id' => $persona->id, 'anulado' => false]);
        $ajeno = Familiar::factory()->create(['anulado' => false]);
        $anulado = Familiar::factory()->create(['usuario_id' => $persona->id, 'anulado' => true, 'motivo_anulacion' => 'Duplicado']);
        $url = $this->url($persona, 'certificados-discapacidad');

        $this->postJson($url, ['persona' => 'servidor', 'numero_conadis' => 'C-1001', 'fecha_emision' => '2024-01-15'])->assertCreated()->assertJsonPath('data.familiar_id', null);
        $this->postJson($url, ['persona' => 'familiar', 'familiar_id' => $hijo->id, 'numero_conadis' => 'C-1002'])->assertCreated()->assertJsonPath('data.familiar_id', $hijo->id);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.1.values.familiar_id', $hijo->apellidos_nombres);

        $this->postJson($url, ['persona' => 'familiar', 'numero_conadis' => 'C-1'])->assertUnprocessable()->assertJsonValidationErrors('familiar_id');
        $this->postJson($url, ['persona' => 'familiar', 'familiar_id' => $ajeno->id, 'numero_conadis' => 'C-1'])->assertUnprocessable()->assertJsonValidationErrors('familiar_id');
        $this->postJson($url, ['persona' => 'familiar', 'familiar_id' => $anulado->id, 'numero_conadis' => 'C-1'])->assertUnprocessable()->assertJsonValidationErrors('familiar_id');
        $this->postJson($url, ['persona' => 'servidor', 'familiar_id' => $hijo->id, 'numero_conadis' => 'C-1'])->assertUnprocessable()->assertJsonValidationErrors('familiar_id');
        $this->postJson($url, ['persona' => 'vecino', 'numero_conadis' => 'C-1'])->assertUnprocessable()->assertJsonValidationErrors('persona');
        $this->postJson($url, ['persona' => 'servidor'])->assertUnprocessable()->assertJsonValidationErrors('numero_conadis');
        $this->postJson($url, ['persona' => 'servidor', 'numero_conadis' => 'C-1', 'fecha_emision' => '2024-05-01', 'vigente_hasta' => '2024-01-01'])->assertUnprocessable()->assertJsonValidationErrors('vigente_hasta');
    }

    public function test_records_are_annulled_with_reason_and_cannot_be_deleted(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $otra = Usuario::factory()->create();
        $declaracion = DeclaracionVerificacion::factory()->create(['usuario_id' => $persona->id]);
        $certificado = CertificadoDiscapacidad::factory()->create(['usuario_id' => $persona->id]);

        foreach (['declaraciones-verificaciones' => $declaracion, 'certificados-discapacidad' => $certificado] as $tipo => $registro) {
            $url = $this->url($persona, $tipo).'/'.$registro->id;
            $this->postJson($url.'/anular', ['motivo_anulacion' => 'no'])->assertUnprocessable()->assertJsonValidationErrors('motivo_anulacion');
            $this->postJson($this->url($otra, $tipo).'/'.$registro->id.'/anular', ['motivo_anulacion' => 'Ficha ajena'])->assertNotFound();
            $this->deleteJson($url)->assertStatus(405);
            $this->postJson($url.'/anular', ['motivo_anulacion' => 'Fecha mal registrada'])->assertOk()->assertJsonPath('data.anulado', true);
            $this->postJson($url.'/anular', ['motivo_anulacion' => 'Segundo intento'])->assertConflict();
            $this->assertDatabaseHas($registro->getTable(), ['id' => $registro->id, 'anulado' => true, 'motivo_anulacion' => 'Fecha mal registrada']);
        }
        $this->postJson($this->url($persona, 'sanciones').'/'.$declaracion->id.'/anular', ['motivo_anulacion' => 'No aplica'])->assertNotFound();
    }

    public function test_previous_certificate_pdf_is_read_only_downloaded_and_preserved(): void
    {
        Storage::fake('local');
        $autor = $this->actuarComo();
        $persona = Usuario::factory()->create();
        // La descarga deja registro en la bitácora, que exige un legajo.
        Legajo::factory()->create(['usuario_id' => $persona->id]);
        $url = $this->url($persona, 'certificados-discapacidad');
        $data = ['persona' => 'servidor', 'numero_conadis' => 'C-PDF', 'fecha_emision' => '2024-01-15'];
        $record = CertificadoDiscapacidad::findOrFail($this->postJson($url, $data)->assertCreated()->json('data.id'));
        $path = $this->pdfAnterior($record, 'certificado.pdf');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.values.pdf_nombre', 'certificado.pdf')->assertJsonMissingPath('data.0.values.pdf_path');
        $download = $this->get($url.'/'.$record->id.'/pdf')->assertOk()->assertDownload('certificado.pdf')->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(self::PDF, $download->streamedContent());

        $this->patchJson($url.'/'.$record->id, ['numero_conadis' => 'C-CORREGIDO', 'registrado_por' => $persona->id] + $data)
            ->assertOk()->assertJsonPath('data.numero_conadis', 'C-CORREGIDO')->assertJsonPath('data.registrado_por', $autor->id);
        $this->assertSame($path, $record->refresh()->pdf_path);

        // El PDF ya no se adjunta en el formulario (se sube como documento del legajo) y el anterior no se toca.
        $this->post($url.'/'.$record->id, $data + ['_method' => 'PATCH', 'numero_conadis' => 'C-OTRO', 'pdf' => UploadedFile::fake()->createWithContent('nuevo.pdf', self::PDF)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $record->refresh();
        $this->assertSame([$path, 'certificado.pdf', 'C-CORREGIDO'], [$record->pdf_path, $record->pdf_nombre, $record->numero_conadis]);
        Storage::disk('local')->assertExists($path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('certificados_discapacidad', 1);
    }

    public function test_records_reject_pdf_files_on_create_and_edit(): void
    {
        Storage::fake('local');
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $url = $this->url($persona, 'certificados-discapacidad');
        $data = ['persona' => 'servidor', 'numero_conadis' => 'C-VALIDO'];
        $this->post($url, $data + ['pdf' => UploadedFile::fake()->createWithContent('c.pdf', self::PDF)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $id = $this->postJson($url, $data)->assertCreated()->json('data.id');
        $this->getJson($url.'/'.$id.'/pdf')->assertNotFound();
        $this->post($url.'/'.$id, ['_method' => 'PATCH', 'pdf' => UploadedFile::fake()->createWithContent('c.pdf', self::PDF), 'numero_conadis' => 'NO-GUARDAR'] + $data, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $this->post($this->url($persona, 'declaraciones-verificaciones'), ['tipo' => 'djbr', 'fecha' => '2025-01-10', 'resultado' => 'presentada', 'pdf' => UploadedFile::fake()->createWithContent('d.pdf', self::PDF)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $this->assertDatabaseHas('certificados_discapacidad', ['id' => $id, 'numero_conadis' => 'C-VALIDO', 'pdf_path' => null]);
        $this->assertDatabaseCount('declaraciones_verificaciones', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_certificate_edit_and_pdf_require_permissions_and_the_correct_person(): void
    {
        Storage::fake('local');
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        // La descarga deja registro en la bitácora, que exige un legajo.
        Legajo::factory()->create(['usuario_id' => $persona->id]);
        $otra = Usuario::factory()->create();
        $url = $this->url($persona, 'certificados-discapacidad');
        $data = ['persona' => 'servidor', 'numero_conadis' => 'C-PRIVADO'];
        $id = $this->postJson($url, $data)->assertCreated()->json('data.id');
        $this->pdfAnterior(CertificadoDiscapacidad::findOrFail($id), 'privado.pdf');
        $ajena = $this->url($otra, 'certificados-discapacidad').'/'.$id;
        $this->patchJson($ajena, $data)->assertNotFound();
        $this->getJson($ajena.'/pdf')->assertNotFound();

        $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
        $this->patchJson($url.'/'.$id, $data)->assertForbidden();
        $this->getJson($url.'/'.$id.'/pdf')->assertForbidden();

        config(['permisos.roles.lector_prueba' => ['legajo.ver']]);
        $lector = $this->asignarRol(Usuario::factory()->create(), 'lector_prueba', [], ['ve_datos_sensibles' => true])->refresh();
        Sanctum::actingAs($lector);
        $this->get($url.'/'.$id.'/pdf')->assertOk();
        $this->patchJson($url.'/'.$id, $data)->assertForbidden();

        $this->actuarComo();
        $this->postJson($url.'/'.$id.'/anular', ['motivo_anulacion' => 'Certificado duplicado'])->assertOk();
        $this->patchJson($url.'/'.$id, $data)->assertConflict();
        $this->get($url.'/'.$id.'/pdf')->assertOk();
    }

    public function test_certificate_edit_preserves_relative_id_and_validates_changes(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $hijo = Familiar::factory()->create(['usuario_id' => $persona->id, 'anulado' => false]);
        $ajeno = Familiar::factory()->create(['anulado' => false]);
        $url = $this->url($persona, 'certificados-discapacidad');
        $data = ['persona' => 'familiar', 'familiar_id' => $hijo->id, 'numero_conadis' => 'C-FAMILIAR'];
        $id = $this->postJson($url, $data)->assertCreated()->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.familiar_id', $hijo->id)->assertJsonPath('data.0.values.familiar_id', $hijo->apellidos_nombres);
        $this->patchJson($url.'/'.$id, ['numero_conadis' => 'C-EDITADO'] + $data)->assertOk()->assertJsonPath('data.familiar_id', $hijo->id);
        $this->patchJson($url.'/'.$id, ['familiar_id' => $ajeno->id] + $data)->assertUnprocessable()->assertJsonValidationErrors('familiar_id');
        $this->patchJson($url.'/'.$id, ['persona' => 'servidor', 'familiar_id' => null, 'numero_conadis' => 'C-SERVIDOR'])->assertOk()->assertJsonPath('data.familiar_id', null);
        $this->patchJson($url.'/'.$id, ['fecha_emision' => '2024-05-01', 'vigente_hasta' => '2024-01-01'] + $data)->assertUnprocessable()->assertJsonValidationErrors('vigente_hasta');
    }

    public function test_declaration_with_previous_pdf_can_be_corrected_without_touching_the_file(): void
    {
        Storage::fake('local');
        $autor = $this->actuarComo();
        $persona = Usuario::factory()->create();
        // La descarga deja registro en la bitácora, que exige un legajo.
        Legajo::factory()->create(['usuario_id' => $persona->id]);
        $url = $this->url($persona, 'declaraciones-verificaciones');
        $data = ['tipo' => 'djbr', 'fecha' => '2025-01-10', 'resultado' => 'presentada', 'anio' => 2025, 'observacion' => 'Original'];
        $record = DeclaracionVerificacion::findOrFail($this->postJson($url, $data)->assertCreated()->assertJsonMissingPath('data.pdf_path')->json('data.id'));
        $path = $this->pdfAnterior($record, 'declaracion.pdf');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.values.pdf_nombre', 'declaracion.pdf')->assertJsonMissingPath('data.0.values.pdf_path');
        $download = $this->get($url.'/'.$record->id.'/pdf')->assertOk()->assertDownload('declaracion.pdf')->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(self::PDF, $download->streamedContent());
        $this->patchJson($url.'/'.$record->id, ['observacion' => 'Corregida', 'registrado_por' => $persona->id] + $data)
            ->assertOk()->assertJsonPath('data.observacion', 'Corregida')->assertJsonPath('data.registrado_por', $autor->id);
        $this->post($url.'/'.$record->id, $data + ['_method' => 'PATCH', 'pdf' => UploadedFile::fake()->createWithContent('nueva.pdf', self::PDF)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $record->refresh();
        $this->assertSame([$path, 'declaracion.pdf', 'Corregida'], [$record->pdf_path, $record->pdf_nombre, $record->observacion]);
        Storage::disk('local')->assertExists($path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('declaraciones_verificaciones', 1);
    }

    public function test_declaration_edit_validates_files_dates_ownership_and_permissions(): void
    {
        Storage::fake('local');
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $otra = Usuario::factory()->create();
        $url = $this->url($persona, 'declaraciones-verificaciones');
        $data = ['tipo' => 'djbr', 'fecha' => '2025-01-10', 'resultado' => 'presentada'];
        $id = $this->postJson($url, $data)->assertCreated()->json('data.id');
        foreach ([
            UploadedFile::fake()->createWithContent('falso.pdf', 'Texto')->mimeType('text/plain'),
            UploadedFile::fake()->create('grande.pdf', 2049, 'application/pdf'),
        ] as $file) {
            $this->post($url.'/'.$id, $data + ['_method' => 'PATCH', 'pdf' => $file], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('pdf');
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->patchJson($url.'/'.$id, ['tipo' => 'invalido', 'fecha' => now()->addDay()->toDateString(), 'resultado' => 'invalido'] + $data)
            ->assertUnprocessable()->assertJsonValidationErrors(['tipo', 'fecha', 'resultado']);
        $this->patchJson($url.'/'.$id, ['vigente_hasta' => '2024-01-01'] + $data)->assertUnprocessable()->assertJsonValidationErrors('vigente_hasta');
        $ajena = $this->url($otra, 'declaraciones-verificaciones').'/'.$id;
        $this->patchJson($ajena, $data)->assertNotFound();
        $this->getJson($ajena.'/pdf')->assertNotFound();
        $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
        $this->patchJson($url.'/'.$id, $data)->assertForbidden();
        $this->getJson($url.'/'.$id.'/pdf')->assertForbidden();
        config(['permisos.roles.lector_declaraciones' => ['legajo.ver']]);
        $lector = $this->asignarRol(Usuario::factory()->create(), 'lector_declaraciones', [], ['ve_datos_sensibles' => true])->refresh();
        Sanctum::actingAs($lector);
        $this->patchJson($url.'/'.$id, $data)->assertForbidden();
        $this->actuarComo();
        $this->postJson($url.'/'.$id.'/anular', ['motivo_anulacion' => 'Registro duplicado'])->assertOk();
        $this->patchJson($url.'/'.$id, $data)->assertConflict();
        $this->assertDatabaseHas('declaraciones_verificaciones', ['id' => $id, 'tipo' => 'djbr', 'pdf_path' => null]);
    }

    public function test_only_administration_can_annul(): void
    {
        // Puede registrar y ve datos sensibles, pero no tiene sistema.administrar.
        config(['permisos.roles.editor_prueba' => ['legajo.ver', 'legajo.editar']]);
        $editor = $this->asignarRol(Usuario::factory()->create(), 'editor_prueba', [], ['ve_datos_sensibles' => true])->refresh();
        Sanctum::actingAs($editor);
        $persona = Usuario::factory()->create();
        $declaracion = DeclaracionVerificacion::factory()->create(['usuario_id' => $persona->id]);

        $this->postJson($this->url($persona, 'declaraciones-verificaciones'), ['tipo' => 'djbr', 'fecha' => '2025-01-10', 'resultado' => 'presentada'])->assertCreated();
        $this->postJson($this->url($persona, 'declaraciones-verificaciones').'/'.$declaracion->id.'/anular', ['motivo_anulacion' => 'Sin permiso'])->assertForbidden();
        $this->assertDatabaseHas('declaraciones_verificaciones', ['id' => $declaracion->id, 'anulado' => false]);
    }

    public function test_sensitive_sections_are_blocked_without_permission(): void
    {
        // asistente_legajo: ve y edita legajos, pero su rol no tiene ve_datos_sensibles.
        $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
        $persona = Usuario::factory()->create(['fecha_nacimiento' => '1990-05-01', 'domicilio' => 'Calle Falsa 123', 'telefono' => '999888777', 'estado_civil' => 'casado']);
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id]);
        SeccionLegajo::query()->findOrFail(1);
        SeccionLegajo::query()->findOrFail(3);
        DocumentoUsuario::factory()->create(['usuario_id' => $persona->id, 'legajo_id' => $legajo->id, 'seccion_legajo_id' => 1]);
        $visible = DocumentoUsuario::factory()->create(['usuario_id' => $persona->id, 'legajo_id' => $legajo->id, 'seccion_legajo_id' => 3]);

        foreach (['familiares', 'declaraciones-verificaciones', 'certificados-discapacidad', 'afiliaciones', 'controversias', 'registros-sst-bienestar'] as $categoria) {
            $this->getJson($this->url($persona, $categoria))->assertForbidden();
        }
        $this->getJson($this->url($persona, 'capacitaciones'))->assertOk();
        $this->getJson($this->url($persona, 'documentos-usuario'))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $visible->id);

        $ficha = $this->getJson('/api/v1/expedientes/'.$persona->id)->assertOk()->assertJsonPath('data.datos_sensibles_ocultos', true);
        $valores = $ficha->json('data.usuario.values');
        foreach (['fecha_nacimiento', 'domicilio', 'telefono', 'estado_civil', 'region', 'con_discapacidad'] as $campo) {
            $this->assertArrayNotHasKey($campo, $valores);
        }
        $this->assertSame($persona->numero_doc_identidad, $valores['numero_doc_identidad']);

        $this->patchJson('/api/v1/expedientes/'.$persona->id, ['telefono' => '911222333'])->assertForbidden();
        $this->postJson($this->url($persona, 'familiares'), ['apellidos_nombres' => 'X', 'parentesco' => 'hijo', 'es_derechohabiente' => true])->assertForbidden();
        $this->postJson($this->url($persona, 'declaraciones-verificaciones'), [])->assertForbidden();
        $this->postJson($this->url($persona, 'certificados-discapacidad'), [])->assertForbidden();
    }

    public function test_admin_sees_sensitive_data(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['domicilio' => 'Calle Falsa 123']);
        $this->getJson('/api/v1/expedientes/'.$persona->id)->assertOk()
            ->assertJsonPath('data.datos_sensibles_ocultos', false)->assertJsonPath('data.usuario.values.domicilio', 'Calle Falsa 123');
        $this->getJson($this->url($persona, 'familiares'))->assertOk();
    }

    public function test_personal_data_uses_closed_lists(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $url = '/api/v1/expedientes/'.$persona->id;
        $this->patchJson($url, ['estado_civil' => 'comprometido'])->assertUnprocessable()->assertJsonValidationErrors('estado_civil');
        $this->patchJson($url, ['tipo_doc_identidad' => 'libreta'])->assertUnprocessable()->assertJsonValidationErrors('tipo_doc_identidad');
        $this->patchJson($url, ['estado_civil' => 'conviviente'])->assertOk()->assertJsonPath('data.usuario.values.estado_civil', 'conviviente');
        $this->patchJson($url, ['estado_civil' => null])->assertOk()->assertJsonPath('data.usuario.values.estado_civil', null);
    }
}
