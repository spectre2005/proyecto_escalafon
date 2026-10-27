<?php

namespace Tests\Feature;

use App\Models\ConexionCorreo;
use App\Models\Legajo;
use App\Models\NotificacionUsuario;
use App\Models\RequerimientoDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Un requerimiento solo pide documentos que el legajo de la persona aceptaría (AplicacionDocumental). Datos ficticios. */
class RequerimientoAplicabilidadTest extends TestCase
{
    use RefreshDatabase;

    private TipoDocumento $basicos;

    private TipoDocumento $grado;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->actuarComo();
        config(['services.gmail.client_id' => 'ficticio', 'services.gmail.client_secret' => 'ficticio']);
        ConexionCorreo::query()->create(['clave' => 'gmail_institucional', 'email' => 'institucion@gmail.com',
            'access_token' => 'ficticio', 'refresh_token' => 'ficticio', 'expira_en' => now()->addHour(),
            'habilitada' => true, 'conectado_por' => $admin->id]);
        Http::preventStrayRequests();
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'gmail-ficticio'])]);
        $this->basicos = $this->tipo('FORM_ESTUDIOS_BASICOS');
        $this->grado = $this->tipo('FORM_TITULO_GRADO_SUPERIOR');
    }

    /** Tipo del catálogo que cargan las migraciones; sole() falla si faltara o estuviera duplicado. */
    private function tipo(string $codigo): TipoDocumento
    {
        return TipoDocumento::query()->where('codigo', $codigo)->where('activo', true)->sole();
    }

    /** Docente de carrera con un vínculo vigente; $puesto fija las banderas del puesto (null = sin responder). */
    private function persona(array $puesto = ['exige_estudios_basicos' => false]): Usuario
    {
        $u = Usuario::factory()->create(['tipo_personal' => 'docente', 'email_institucional' => fake()->unique()->userName().'@gmail.com']);
        Legajo::factory()->create(['usuario_id' => $u->id, 'estado_legajo' => 'activo']);
        VinculoLaboral::factory()->create(['usuario_id' => $u->id, 'tipo_personal' => 'docente', 'regimen_laboral' => 'carrera', ...$puesto]);

        return $u;
    }

    private function datos(Usuario $u, array $tipos): array
    {
        return ['clase' => 'documentos', 'usuario_id' => $u->id, 'tipo_documento_ids' => $tipos,
            'descripcion' => 'Presentar el documento', 'fecha_limite' => now('America/Lima')->addWeek()->toDateString()];
    }

    /** @return array<string, array{no_aplica: ?string, por_definir: ?string}> */
    private function opciones(Usuario $u): array
    {
        return collect($this->getJson('/api/v1/solicitudes-legajo/opciones?usuario_id='.$u->id)->assertOk()->json('tipos'))
            ->keyBy('id')->map(fn (array $t): array => ['no_aplica' => $t['no_aplica'], 'por_definir' => $t['por_definir']])->all();
    }

    private function sinEfectos(): void
    {
        $this->assertDatabaseCount('requerimientos_documentos', 0);
        $this->assertDatabaseCount('notificaciones_usuario', 0);
        Http::assertNothingSent();
    }

    public function test_position_without_basic_studies_cannot_request_that_certificate_even_through_the_api(): void
    {
        $u = $this->persona();
        $tipos = $this->opciones($u);
        $this->assertStringContainsString('«El puesto exige estudios básicos» está en No', $tipos[$this->basicos->id]['no_aplica']);
        $this->assertStringContainsString('vínculo laboral (sección 02)', $tipos[$this->basicos->id]['no_aplica']);
        $this->assertNull($tipos[$this->grado->id]['no_aplica']);

        $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, [$this->basicos->id]))->assertUnprocessable()
            ->assertJsonValidationErrors('tipo_documento_ids')
            ->assertJsonPath('errors.tipo_documento_ids.0', fn (string $m) => str_starts_with($m, '«Certificado oficial de estudios básicos» no corresponde a esta persona.'));
        // Un lote con un tipo válido y otro inválido no registra ninguno.
        $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, [$this->grado->id, $this->basicos->id]))->assertUnprocessable();
        // La forma antigua (un solo tipo_documento_id) también se valida.
        $this->postJson('/api/v1/solicitudes-legajo', [...array_diff_key($this->datos($u, []), ['tipo_documento_ids' => 0]), 'tipo_documento_id' => $this->basicos->id])
            ->assertUnprocessable()->assertJsonValidationErrors('tipo_documento_id');
        $this->sinEfectos();
    }

    public function test_position_that_requires_basic_studies_allows_requesting_and_sending_it(): void
    {
        $u = $this->persona(['exige_estudios_basicos' => true]);
        $tipos = $this->opciones($u);
        $this->assertNull($tipos[$this->basicos->id]['no_aplica']);
        $this->assertNull($tipos[$this->basicos->id]['por_definir']);
        $correo = $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, [$this->basicos->id, $this->grado->id]))
            ->assertCreated()->assertJsonCount(2, 'correos')->json('correos.0');
        $this->postJson('/api/v1/solicitudes-legajo/'.$correo['requerimiento_id'].'/enviar', ['notificacion_id' => $correo['id']])
            ->assertOk()->assertJsonPath('correo.correo_estado', 'enviado');
    }

    public function test_other_conditioned_types_follow_the_existing_rules_and_options_change_with_the_person(): void
    {
        $regimen = $this->tipo('S11_G_NO_RENOVACION_CAS');
        $djbr = $this->tipo('S01_E_DJ_BIENES_RENTAS');
        $colegiatura = $this->tipo('FORM_COLEGIATURA');
        // El catálogo aún no clasifica ningún tipo por tipo de personal (todos «ambos»): para la regla se marca uno
        // existente como lo propone docs/propuesta-aplicabilidad-tipos-documento.md (solo en esta base de pruebas).
        $administrativo = tap($this->tipo('S02_E_RESOLUCION_CARRERA'))->update(['aplica_tipo_personal' => 'administrativo']);
        $this->assertFalse($regimen->aplica_carrera);
        $this->assertTrue($djbr->requiere_djbr);
        $this->assertTrue($colegiatura->requiere_colegiatura);
        $this->assertTrue($this->basicos->requiere_estudios_basicos);

        $ana = $this->persona(['exige_estudios_basicos' => false, 'obligado_djbr' => false, 'exige_colegiatura' => true]);
        $tipos = $this->opciones($ana);
        $this->assertSame('No corresponde a su régimen laboral.', $tipos[$regimen->id]['no_aplica']);
        $this->assertSame('Solo para personal administrativo.', $tipos[$administrativo->id]['no_aplica']);
        $this->assertStringContainsString('declaración jurada de bienes y rentas', $tipos[$djbr->id]['no_aplica']);
        $this->assertNull($tipos[$colegiatura->id]['no_aplica']);

        // Otra persona, otras condiciones: el selector cambia con ella.
        $luis = $this->persona(['exige_estudios_basicos' => true, 'obligado_djbr' => true, 'exige_colegiatura' => false]);
        $tipos = $this->opciones($luis);
        $this->assertNull($tipos[$this->basicos->id]['no_aplica']);
        $this->assertNull($tipos[$djbr->id]['no_aplica']);
        $this->assertStringContainsString('colegiatura', $tipos[$colegiatura->id]['no_aplica']);

        foreach ([$regimen, $administrativo, $djbr] as $tipo) {
            $this->postJson('/api/v1/solicitudes-legajo', $this->datos($ana, [$tipo->id]))->assertUnprocessable();
        }
        $this->postJson('/api/v1/solicitudes-legajo', $this->datos($luis, [$colegiatura->id]))->assertUnprocessable();
        $this->sinEfectos();
    }

    public function test_undetermined_applicability_is_registered_but_cannot_be_sent_until_completed(): void
    {
        $u = $this->persona(['exige_estudios_basicos' => null]);
        $this->assertStringContainsString('«El puesto exige estudios básicos» está sin responder en el vínculo laboral (sección 02)',
            $this->opciones($u)[$this->basicos->id]['por_definir']);
        $correo = $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, [$this->basicos->id]))->assertCreated()->json('correos.0');
        $url = '/api/v1/solicitudes-legajo/'.$correo['requerimiento_id'];
        $this->postJson($url.'/enviar', ['notificacion_id' => $correo['id']])->assertUnprocessable()
            ->assertJsonPath('errors.tipo_documento_id.0', fn (string $m) => str_contains($m, 'está sin responder') && str_contains($m, 'Complétalo y vuelve a enviar'));
        $seguimiento = RequerimientoDocumento::query()->find($correo['requerimiento_id'])->seguimiento;
        $this->postJson($url.'/gestionar', ['accion' => 'recordar', 'mensaje' => 'Recuerda'])->assertUnprocessable();
        $this->assertSame($seguimiento, RequerimientoDocumento::query()->find($correo['requerimiento_id'])->seguimiento);
        $this->assertDatabaseCount('notificaciones_usuario', 1);
        $this->assertDatabaseHas('notificaciones_usuario', ['id' => $correo['id'], 'correo_estado' => 'pendiente', 'correo_intentos' => 0]);
        Http::assertNothingSent();

        VinculoLaboral::query()->where('usuario_id', $u->id)->update(['exige_estudios_basicos' => true]);
        $this->postJson($url.'/enviar', ['notificacion_id' => $correo['id']])->assertOk()->assertJsonPath('correo.correo_estado', 'enviado');
    }

    public function test_type_that_stopped_applying_after_registration_is_not_sent_nor_reminded(): void
    {
        $u = $this->persona(['exige_estudios_basicos' => true]);
        $correo = $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, [$this->basicos->id]))->assertCreated()->json('correos.0');
        VinculoLaboral::query()->where('usuario_id', $u->id)->update(['exige_estudios_basicos' => false]);
        $url = '/api/v1/solicitudes-legajo/'.$correo['requerimiento_id'];
        $this->postJson($url.'/enviar', ['notificacion_id' => $correo['id']])->assertUnprocessable()
            ->assertJsonPath('errors.tipo_documento_id.0', fn (string $m) => str_contains($m, 'no corresponde a esta persona') && str_contains($m, 'cancélalo'));
        $this->postJson($url.'/gestionar', ['accion' => 'recordar', 'mensaje' => 'Recuerda'])->assertUnprocessable();
        $this->assertSame(1, NotificacionUsuario::query()->count());
        Http::assertNothingSent();
        // Las demás acciones siguen disponibles para resolverlo.
        $this->postJson($url.'/gestionar', ['accion' => 'cancelar', 'mensaje' => 'Ya no corresponde'])->assertOk();
    }

    public function test_closed_requirement_keeps_its_previous_send_behavior_even_if_the_type_no_longer_applies(): void
    {
        $u = $this->persona(['exige_estudios_basicos' => true]);
        $correo = $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, [$this->basicos->id]))->assertCreated()->json('correos.0');
        $url = '/api/v1/solicitudes-legajo/'.$correo['requerimiento_id'];
        $this->postJson($url.'/gestionar', ['accion' => 'cancelar', 'mensaje' => 'Ya no se necesita'])->assertOk();
        VinculoLaboral::query()->where('usuario_id', $u->id)->update(['exige_estudios_basicos' => false]);
        // Cerrado: el aviso se cancela como antes (CorreoRequerimiento::procesar), sin el error de aplicabilidad.
        $this->postJson($url.'/enviar', ['notificacion_id' => $correo['id']])->assertOk()->assertJsonPath('correo.correo_estado', 'cancelado');
        $this->postJson($url.'/gestionar', ['accion' => 'recordar', 'mensaje' => 'Recuerda'])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_every_rejected_type_is_reported_in_the_validation_errors(): void
    {
        $u = $this->persona(['exige_estudios_basicos' => false, 'exige_colegiatura' => false]);
        $errores = $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, [$this->basicos->id, $this->tipo('FORM_COLEGIATURA')->id]))
            ->assertUnprocessable()->json('errors.tipo_documento_ids');
        $this->assertCount(2, $errores);
        $this->sinEfectos();
    }

    public function test_options_without_person_keep_their_format_and_an_unknown_person_is_rejected(): void
    {
        $tipos = $this->getJson('/api/v1/solicitudes-legajo/opciones')->assertOk()->json('tipos');
        $this->assertSame(['id', 'nombre'], array_keys($tipos[0]));
        $this->getJson('/api/v1/solicitudes-legajo/opciones?usuario_id='.Usuario::factory()->create()->id)->assertNotFound();
    }

    public function test_requests_without_document_type_keep_working(): void
    {
        $u = $this->persona();
        $correo = $this->postJson('/api/v1/solicitudes-legajo', [...$this->datos($u, []), 'descripcion' => 'Actualizar el domicilio'])->assertCreated()->json('correos.0');
        $this->postJson('/api/v1/solicitudes-legajo/'.$correo['requerimiento_id'].'/enviar', ['notificacion_id' => $correo['id']])
            ->assertOk()->assertJsonPath('correo.correo_estado', 'enviado');
    }
}
