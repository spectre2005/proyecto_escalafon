<?php

namespace Tests\Feature;

use App\Models\CertificadoDiscapacidad;
use App\Models\DeclaracionVerificacion;
use App\Models\DocumentoUsuario;
use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\SeccionLegajo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Seccion01ApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_records_are_annulled_with_reason_never_edited_or_deleted(): void
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
            // No existe ruta para editar ni borrar estos registros desde la ficha.
            $this->patchJson($url, ['numero_conadis' => 'X'])->assertNotFound();
            $this->deleteJson($url)->assertNotFound();
            $this->postJson($url.'/anular', ['motivo_anulacion' => 'Fecha mal registrada'])->assertOk()->assertJsonPath('data.anulado', true);
            $this->postJson($url.'/anular', ['motivo_anulacion' => 'Segundo intento'])->assertConflict();
            $this->assertDatabaseHas($registro->getTable(), ['id' => $registro->id, 'anulado' => true, 'motivo_anulacion' => 'Fecha mal registrada']);
        }
        $this->postJson($this->url($persona, 'sanciones').'/'.$declaracion->id.'/anular', ['motivo_anulacion' => 'No aplica'])->assertNotFound();
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
        SeccionLegajo::factory()->create(['id' => 1, 'nombre' => 'Información personal y familiar']);
        SeccionLegajo::factory()->create(['id' => 3, 'nombre' => 'Formación académica y capacitación']);
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
