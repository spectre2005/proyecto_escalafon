<?php

namespace Tests\Feature;

use App\Models\Incorporacion;
use App\Models\Legajo;
use App\Models\Rol;
use App\Models\Usuario;
use Database\Seeders\RegimenNormativoSeeder;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncorporacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_reuses_person_and_preserves_existing_legajo(): void
    {
        $this->actuarComo();
        $u = Usuario::factory()->create();
        $l = Legajo::factory()->create(['usuario_id' => $u->id]);
        $payload = ['usuario_id' => $u->id, 'cargo' => 'Analista', 'documentos_exigidos' => ['DNI']];
        $this->postJson('/api/v1/incorporaciones', $payload)->assertCreated()->assertJsonPath('data.usuario.id', $u->id)->assertJsonPath('data.estado', 'registrado');
        $this->postJson('/api/v1/incorporaciones', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('legajos', 1);
        $this->assertDatabaseHas('legajos', ['id' => $l->id]);
    }

    public function test_new_candidate_is_inactive_and_invalid_regime_is_rejected(): void
    {
        $this->actuarComo();
        $payload = ['nombres' => 'Rosa', 'apellido_paterno' => 'Quispe', 'numero_doc_identidad' => '99887766', 'email_institucional' => 'rosa@example.test', 'tipo_personal' => 'docente', 'regimen_laboral' => 'CAS', 'cargo' => 'Docente', 'documentos_exigidos' => ['DNI']];
        $this->postJson('/api/v1/incorporaciones', $payload)->assertUnprocessable();
        $payload['regimen_laboral'] = '30220';
        $this->postJson('/api/v1/incorporaciones', $payload)->assertCreated()->assertJsonPath('data.usuario.activo', false);
    }

    public function test_delegation_is_scoped_and_does_not_grant_admission_or_self_review(): void
    {
        Storage::fake('local');
        $admin = $this->actuarComo();
        $delegate = $this->asignarRol(Usuario::factory()->create(), 'asistente_legajo')->refresh();
        $i = Incorporacion::factory()->create();
        $other = Incorporacion::factory()->create();
        $url = '/api/v1/incorporaciones/'.$i->id;
        $this->patchJson($url, ['version' => 1, 'accion' => 'delegar', 'responsable_id' => $delegate->id, 'tareas' => ['Cargar DNI']])->assertOk();
        Sanctum::actingAs($delegate);
        $this->getJson('/api/v1/incorporaciones')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/incorporaciones/'.$other->id)->assertForbidden();
        $this->patchJson($url, ['version' => 2, 'accion' => 'admitir'])->assertForbidden();
        $doc = $i->documentos[0]['id'];
        $this->post($url.'/documentos/'.$doc, ['version' => 2, 'pdf' => UploadedFile::fake()->createWithContent('dni.pdf', "%PDF-1.4\n1 0 obj <</Type /Catalog>> endobj\n%%EOF")], ['Accept' => 'application/json'])->assertOk();
        $this->patchJson($url, ['version' => 3, 'accion' => 'revision'])->assertOk();
        $this->patchJson($url, ['version' => 4, 'accion' => 'revisar_documento', 'documento_id' => $doc, 'estado_documento' => 'revisado'])->assertForbidden();
        Sanctum::actingAs($admin);
        $this->patchJson($url, ['version' => 4, 'accion' => 'revisar_documento', 'documento_id' => $doc, 'estado_documento' => 'revisado'])->assertOk()->assertJsonPath('data.revisados', 1)->assertJsonMissingPath('data.documentos.0.versiones.0.path');
        $this->patchJson($url, ['version' => 4, 'accion' => 'admitir'])->assertConflict();
    }

    public function test_incorporation_is_atomic_reuses_legajo_and_activates_account_once(): void
    {
        $this->seed([SeccionesLegajoCatalogoSeeder::class, RegimenNormativoSeeder::class]);
        $this->actuarComo();
        $u = Usuario::factory()->create(['activo' => false, 'tipo_personal' => 'administrativo', 'regimen_laboral' => 'CAS']);
        $legajo = Legajo::factory()->create(['usuario_id' => $u->id, 'estado_legajo' => 'activo']);
        $i = Incorporacion::factory()->create(['usuario_id' => $u->id, 'estado' => 'en_revision', 'numero_resolucion' => 'R-123', 'fecha_resolucion' => '2026-10-01', 'fecha_inicio' => '2026-10-09']);
        $url = '/api/v1/incorporaciones/'.$i->id;
        $this->patchJson($url, ['version' => 1, 'accion' => 'admitir'])->assertUnprocessable();
        $docs = $i->documentos;
        $docs[0]['estado'] = 'revisado';
        $i->update(['documentos' => $docs]);
        $this->patchJson($url, ['version' => 1, 'accion' => 'admitir'])->assertOk()->assertJsonPath('data.estado', 'admitido');
        $payload = ['version' => 2, 'accion' => 'incorporar', 'rol_id' => Rol::where('codigo', 'personal')->firstOrFail()->id];
        $this->patchJson($url, $payload)->assertUnprocessable();
        $this->assertDatabaseCount('vinculos_laborales', 0);
        $this->patchJson($url, $payload + ['password' => 'Acceso12345'])->assertOk()->assertJsonPath('data.estado', 'incorporado');
        $this->assertTrue($u->fresh()->activo);
        $this->assertTrue(Hash::check('Acceso12345', $u->fresh()->password));
        $this->assertDatabaseCount('legajos', 1);
        $this->assertDatabaseHas('legajos', ['id' => $legajo->id]);
        $this->assertDatabaseCount('vinculos_laborales', 1);
        $this->patchJson($url, [...$payload, 'version' => 3, 'password' => 'Acceso12345'])->assertConflict();
    }

    public function test_personal_role_can_read_only_own_legajo(): void
    {
        $u = $this->actuarComo(null, 'personal');
        $other = Usuario::factory()->create();
        $this->getJson('/api/v1/expedientes/'.$u->id)->assertOk();
        $this->getJson('/api/v1/expedientes/'.$other->id)->assertForbidden();
        $this->getJson('/api/v1/expedientes')->assertForbidden();
        $this->getJson('/api/v1/usuarios')->assertForbidden();
        $this->patchJson('/api/v1/expedientes/'.$u->id, ['nombres' => 'Cambio'])->assertForbidden();
    }

    public function test_complete_flow_opens_missing_legajo_and_blocks_uploader_review(): void
    {
        Storage::fake('local');
        $this->seed(RegimenNormativoSeeder::class);
        $uploader = $this->actuarComo();
        $reviewer = $this->asignarRol(Usuario::factory()->create())->refresh();
        $candidate = Usuario::factory()->create(['activo' => false, 'tipo_personal' => 'administrativo', 'regimen_laboral' => 'CAS']);
        $i = $this->postJson('/api/v1/incorporaciones', [
            'usuario_id' => $candidate->id, 'cargo' => 'Analista', 'numero_resolucion' => 'R-15',
            'fecha_resolucion' => '2026-10-01', 'fecha_inicio' => '2026-10-09', 'documentos_exigidos' => ['DNI'],
        ])->assertCreated()->json('data');
        $url = '/api/v1/incorporaciones/'.$i['id'];
        $doc = $i['documentos'][0]['id'];
        $this->post($url.'/documentos/'.$doc, ['version' => 1, 'pdf' => UploadedFile::fake()->createWithContent('dni.pdf', "%PDF-1.4\n1 0 obj <</Type /Catalog>> endobj\n%%EOF")], ['Accept' => 'application/json'])->assertOk();
        $this->patchJson($url, ['version' => 2, 'accion' => 'revision'])->assertOk();
        $this->patchJson($url, ['version' => 3, 'accion' => 'revisar_documento', 'documento_id' => $doc, 'estado_documento' => 'revisado'])->assertForbidden();
        Sanctum::actingAs($reviewer);
        $this->patchJson($url, ['version' => 3, 'accion' => 'revisar_documento', 'documento_id' => $doc, 'estado_documento' => 'revisado'])->assertOk();
        $this->patchJson($url, ['version' => 4, 'accion' => 'firma'])->assertOk()->assertJsonPath('data.version', 5);
        $this->patchJson($url, ['version' => 5, 'accion' => 'admitir'])->assertOk();
        $this->patchJson($url, ['version' => 6, 'accion' => 'incorporar', 'rol_id' => Rol::where('codigo', 'personal')->firstOrFail()->id, 'password' => 'Acceso12345'])->assertOk();
        $this->assertDatabaseCount('legajos', 1);
        $this->assertDatabaseCount('legajo_secciones', 13);
        $this->assertDatabaseCount('vinculos_laborales', 1);
        $record = Incorporacion::findOrFail($i['id']);
        $this->assertStringNotContainsString('Acceso12345', json_encode($record->historial));
        $this->assertTrue(Storage::disk('local')->exists($record->documentos[0]['versiones'][0]['path']));
        $this->assertSame($uploader->id, $record->documentos[0]['versiones'][0]['subido_por']);
        $this->getJson('/api/v1/incorporaciones?archivo=1&tipo=administrativo&cargo=analista')->assertOk()->assertJsonPath('total', 1);
        Sanctum::actingAs($candidate->fresh());
        $this->getJson('/api/v1/expedientes/'.$candidate->id.'/seccion-01')->assertOk();
        $this->getJson('/api/v1/expedientes/'.$reviewer->id.'/seccion-01')->assertForbidden();
    }

    public function test_admin_can_enable_existing_staff_as_delegate_without_replacing_the_legajo(): void
    {
        $admin = $this->actuarComo();
        $staff = Usuario::factory()->create(['activo' => false]);
        $payload = ['usuario_id' => $staff->id, 'password' => 'Asistente1234'];
        $this->postJson('/api/v1/incorporaciones/responsables', $payload)->assertUnprocessable();
        Legajo::factory()->create(['usuario_id' => $staff->id, 'estado_legajo' => 'activo']);
        $this->postJson('/api/v1/incorporaciones/responsables', $payload)->assertOk();
        $this->assertTrue(Hash::check('Asistente1234', $staff->fresh()->password));
        $this->assertTrue($staff->fresh()->tienePermiso('legajo.editar'));
        $this->assertFalse($staff->fresh()->tienePermiso('sistema.administrar'));
        $this->assertDatabaseCount('legajos', 1);
        $this->postJson('/api/v1/incorporaciones/responsables', $payload)->assertConflict();
        Sanctum::actingAs($staff->fresh());
        $this->postJson('/api/v1/incorporaciones/responsables', ['usuario_id' => $admin->id])->assertForbidden();
    }
}
