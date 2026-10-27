<?php

namespace Tests\Feature;

use App\Models\ConexionCorreo;
use App\Models\Legajo;
use App\Models\NotificacionUsuario;
use App\Models\ParametroSistema;
use App\Models\RequerimientoDocumento;
use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\CorreoRequerimiento;
use App\Support\GmailCorreo;
use App\Support\MensajeRequerimiento;
use App\Support\RecepcionGmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MensajeRequerimientoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gmail.client_id' => 'cliente-ficticio', 'services.gmail.client_secret' => 'secreto-ficticio', 'services.gmail.reply_to' => null]);
        Http::preventStrayRequests();
    }

    private function institucion(array $settings = ['name' => 'Universidad Ficticia', 'area' => 'Oficina de Recursos Humanos']): void
    {
        DB::table('institution_settings')->insert(['id' => 1, 'settings' => json_encode($settings), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function gmail(bool $lectura = true): void
    {
        $admin = $this->actuarComo();
        ConexionCorreo::query()->create(['clave' => 'gmail_institucional', 'email' => 'institucion@gmail.com',
            'access_token' => 'access-ficticio', 'refresh_token' => 'refresh-ficticio', 'expira_en' => now()->addHour(),
            'habilitada' => true, 'conectado_por' => $admin->id, 'alcances' => $lectura ? [GmailCorreo::ALCANCE, GmailCorreo::LECTURA] : [GmailCorreo::ALCANCE]]);
    }

    private function requerimiento(array $persona = [], array $datos = [], bool $conTipo = true): RequerimientoDocumento
    {
        $u = Usuario::factory()->create(['nombres' => 'Ana María', 'apellido_paterno' => 'Quispe', 'apellido_materno' => 'Rojas',
            'email_institucional' => 'docente@example.test', ...$persona]);
        Legajo::factory()->create(['usuario_id' => $u->id, 'estado_legajo' => 'activo']);
        $tipo = null;
        if ($conTipo) {
            $seccion = SeccionLegajo::query()->find(3) ?? SeccionLegajo::factory()->create(['id' => 3, 'nombre' => 'Formación académica y capacitación']);
            $tipo = TipoDocumento::factory()->create(['nombre' => 'Constancia de estudios', 'seccion_legajo_id' => $seccion->id]);
        }

        return RequerimientoDocumento::factory()->create(['usuario_id' => $u->id, 'estado' => 'abierto', 'cerrado_en' => null,
            'tipo_documento_id' => $tipo?->id, 'descripcion' => ($tipo ? 'Constancia de estudios: ' : '').'Presentar la constancia del último ciclo',
            'fecha_limite' => '2026-11-20', ...$datos]);
    }

    private function solicitud(RequerimientoDocumento $r): array
    {
        return MensajeRequerimiento::construir(CorreoRequerimiento::programar($r, MensajeRequerimiento::TITULO_SOLICITUD, $r->descripcion), $r);
    }

    private function sinEtiquetas(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function test_requirement_notice_has_subject_html_and_text_with_the_same_essential_information(): void
    {
        $this->institucion();
        $this->gmail();
        $r = $this->requerimiento();
        $correo = $this->solicitud($r);
        $ref = '[REQ-'.$r->id.']';
        $this->assertSame($ref.' Universidad Ficticia · Requerimiento de documentación: Constancia de estudios · vence 20/11/2026', $correo['asunto']);
        $this->assertStringStartsWith('<!DOCTYPE html>', $correo['html']);
        $html = $this->sinEtiquetas($correo['html']);
        foreach (['Universidad Ficticia', 'Oficina de Recursos Humanos', 'Buen día, Ana María Quispe Rojas:',
            'Te solicitamos la documentación que se detalla a continuación', 'Constancia de estudios',
            '03 · Formación académica y capacitación', 'Presentar la constancia del último ciclo', '20/11/2026', $ref,
            'Responde a este mismo correo, desde la dirección en la que lo recibiste', 'Conserva la referencia '.$ref.' en el asunto',
            'formato PDF, de hasta 2 MB cada uno, con un máximo de 10 archivos por correo. Los archivos en otro formato no se incorporan.',
            'No necesitas ingresar al sistema', 'revisados por el área responsable de tu legajo (Oficina de Recursos Humanos)',
            'no significa que el documento haya sido aceptado', 'mensaje automático', 'información personal sensible'] as $esencial) {
            $this->assertStringContainsString($esencial, $correo['texto']);
            $this->assertStringContainsString($esencial, $html);
        }
        // El documento no se repite dentro del detalle, ni el mensaje cuando es la misma descripción.
        $this->assertStringNotContainsString('Detalle: Constancia de estudios:', $correo['texto']);
        $this->assertStringNotContainsString('MENSAJE', $correo['texto']);
    }

    public function test_reminder_is_brief_follows_the_same_design_and_does_not_look_like_a_new_request(): void
    {
        $this->institucion();
        $r = $this->requerimiento(datos: ['prorroga_hasta' => '2026-11-30']);
        $n = CorreoRequerimiento::programar($r, MensajeRequerimiento::TITULO_RECORDATORIO, 'Aún no recibimos tu constancia.');
        $correo = MensajeRequerimiento::construir($n, $r);
        $ref = '[REQ-'.$r->id.']';
        $this->assertSame($ref.' Universidad Ficticia · Seguimiento de requerimiento: Constancia de estudios · vence 30/11/2026', $correo['asunto']);
        $html = $this->sinEtiquetas($correo['html']);
        foreach (['Te recordamos que tienes un requerimiento de documentación pendiente.', 'Requerimiento pendiente', 'Constancia de estudios', 'Adjunta el documento en un solo archivo PDF de hasta 2 MB.',
            '30/11/2026 (plazo prorrogado; fecha original 20/11/2026)', 'Aún no recibimos tu constancia.', 'Responde a este mismo correo',
            'Conserva la referencia '.$ref, 'no significa que el documento haya sido aceptado'] as $esencial) {
            // En texto plano los títulos de bloque van en mayúsculas.
            $this->assertStringContainsStringIgnoringCase($esencial, $correo['texto']);
            $this->assertStringContainsString($esencial, $html);
        }
        foreach (['Documentación solicitada', 'Requerimiento de documentación:', 'Sección:'] as $propioDeLaSolicitud) {
            $this->assertStringNotContainsStringIgnoringCase($propioDeLaSolicitud, $correo['texto'].$html);
        }
    }

    public function test_dynamic_data_is_escaped_in_html_and_kept_literal_in_plain_text(): void
    {
        $this->institucion(['name' => 'Uni <b>Ficticia</b> & Cía', 'area' => 'RR. HH. "Central"', 'reportColor' => 'red;background:url(x)']);
        $r = $this->requerimiento(['nombres' => '<script>alert(1)</script>', 'apellido_paterno' => "O'Brien", 'apellido_materno' => null]);
        $n = CorreoRequerimiento::programar($r, MensajeRequerimiento::TITULO_RECORDATORIO, "Línea 1\n<img src=x onerror=alert(1)>");
        $correo = MensajeRequerimiento::construir($n, $r);
        foreach (['<script>', '<img', '<b>Ficticia', 'url(x)'] as $marcado) {
            $this->assertStringNotContainsString($marcado, $correo['html']);
        }
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; O&#039;Brien', $correo['html']);
        $this->assertStringContainsString('Uni &lt;b&gt;Ficticia&lt;/b&gt; &amp; Cía', $correo['html']);
        $this->assertStringContainsString('Línea 1<br>', $correo['html']);
        $this->assertStringContainsString('#0f766e', $correo['html']);
        $this->assertStringContainsString("Buen día, <script>alert(1)</script> O'Brien:", $correo['texto']);
    }

    public function test_attachment_instructions_follow_the_real_limits_of_each_delivery_channel(): void
    {
        ParametroSistema::factory()->create(['clave' => 'tamano_maximo_pdf_kb', 'valor' => '1500', 'tipo_dato' => 'entero']);
        $r = $this->requerimiento();
        // Sin lectura de Gmail (SMTP o Gmail solo de envío), el personal registra la entrega a mano con un único PDF.
        $this->gmail(lectura: false);
        $manual = $this->solicitud($r);
        $this->assertStringContainsString('Adjunta el documento en un solo archivo PDF de hasta 1500 KB.', $manual['texto']);
        $this->assertStringContainsString('Adjunta el documento en un solo archivo PDF de hasta 1500 KB.', $this->sinEtiquetas($manual['html']));
        $this->assertStringNotContainsString('archivos por correo', $manual['texto']);
        $this->assertStringNotContainsString('se incorporan', $manual['texto']);
        ConexionCorreo::query()->delete();
        $this->assertStringContainsString('Adjunta el documento en un solo archivo PDF de hasta 1500 KB.', $this->solicitud($r)['texto']);
        $this->gmail();
        $automatica = $this->solicitud($r)['texto'];
        $this->assertStringContainsString('de hasta 1500 KB cada uno, con un máximo de '.RecepcionGmail::MAXIMO_ADJUNTOS.' archivos por correo.', $automatica);
        $this->assertStringNotContainsString('un solo archivo', $automatica);
    }

    public function test_incomplete_data_produces_a_clear_message_without_empty_fields(): void
    {
        $r = $this->requerimiento(['nombres' => '', 'apellido_paterno' => '', 'apellido_materno' => null], conTipo: false);
        $r->fecha_limite = null;
        $n = NotificacionUsuario::query()->create(['usuario_id' => $r->usuario_id, 'tipo' => 'solicitud_legajo', 'titulo' => 'Aviso anterior', 'mensaje' => '', 'requerimiento_id' => $r->id]);
        $correo = MensajeRequerimiento::construir($n, $r);
        $this->assertSame('[REQ-'.$r->id.'] Requerimiento de documentación', $correo['asunto']);
        $this->assertStringContainsString("Buen día:\n", $correo['texto']);
        $this->assertStringContainsString('Te solicitamos la documentación que se detalla a continuación', $correo['texto']);
        $this->assertStringContainsString('revisados por el área responsable de tu legajo. Responder', $correo['texto']);
        $this->assertStringContainsString('Detalle: Presentar la constancia del último ciclo', $correo['texto']);
        $this->assertStringStartsWith('Buen día:', $correo['texto']);
        foreach (['Documento:', 'Sección:', 'Fecha límite', 'Mi institución', ' · ·', ': :', 'MENSAJE'] as $vacio) {
            $this->assertStringNotContainsString($vacio, $correo['texto']);
        }
        $this->assertStringNotContainsString('Mi institución', $correo['html']);
    }

    public function test_unconfigured_placeholder_name_is_not_presented_as_the_institution(): void
    {
        $this->institucion(['name' => 'Mi institución']);
        $correo = $this->solicitud($this->requerimiento());
        $this->assertStringNotContainsString('Mi institución', $correo['asunto'].$correo['texto'].$correo['html']);
    }

    public function test_default_area_is_not_presented_as_the_confirmed_office_but_the_institution_name_is_kept(): void
    {
        $this->institucion(['name' => 'Universidad Ficticia', 'area' => 'Gestión de personas']);
        $correo = $this->solicitud($this->requerimiento());
        $this->assertStringNotContainsString('Gestión de personas', $correo['asunto'].$correo['texto'].$correo['html']);
        $this->assertStringStartsWith("Universidad Ficticia\n\nBuen día,", $correo['texto']);
        $this->assertStringContainsString('Universidad Ficticia', $this->sinEtiquetas($correo['html']));
        $this->assertStringContainsString('Te solicitamos la documentación', $correo['texto']);
        $this->assertStringContainsString('revisados por el área responsable de tu legajo. Responder', $correo['texto']);
    }

    public function test_gmail_sends_multipart_alternative_from_the_connected_account_with_the_reference_first(): void
    {
        $this->institucion();
        $this->gmail();
        $r = $this->requerimiento();
        $n = CorreoRequerimiento::programar($r, MensajeRequerimiento::TITULO_SOLICITUD, $r->descripcion);
        Mail::shouldReceive('raw')->never();
        Mail::shouldReceive('send')->never();
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'gmail-ficticio'])]);
        $this->assertSame(1, CorreoRequerimiento::procesar([$n->id]));
        Http::assertSent(function ($request) use ($r) {
            $raw = base64_decode(strtr($request['raw'], '-_', '+/'));
            $cabeceras = iconv_mime_decode_headers(substr($raw, 0, strpos($raw, "\r\n\r\n")), 0, 'UTF-8');

            return str_contains($raw, 'Content-Type: multipart/alternative') && str_contains($raw, 'Content-Type: text/plain; charset=utf-8')
                && str_contains($raw, 'Content-Type: text/html; charset=utf-8')
                && $cabeceras['From'] === 'institucion@gmail.com' && $cabeceras['Reply-To'] === 'institucion@gmail.com' && $cabeceras['To'] === 'docente@example.test'
                && str_starts_with($cabeceras['Subject'], '[REQ-'.$r->id.'] Universidad Ficticia · Requerimiento de documentación');
        });
        $this->assertSame('enviado', $n->fresh()->correo_estado);
    }

    public function test_reminder_action_sends_the_reminder_format_and_failure_is_not_marked_as_sent(): void
    {
        $this->gmail();
        $r = $this->requerimiento();
        $estado = 503;
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => function () use (&$estado) {
            return Http::response($estado === 200 ? ['id' => 'gmail-ficticio'] : [], $estado);
        }]);
        $this->postJson('/api/v1/solicitudes-legajo/'.$r->id.'/gestionar', ['accion' => 'recordar', 'mensaje' => 'Recuerda tu constancia.'])
            ->assertOk()->assertJsonPath('correo.correo_estado', 'error');
        $this->assertNull(NotificacionUsuario::query()->where('requerimiento_id', $r->id)->value('correo_enviado_en'));
        Http::assertSent(function ($request) use ($r) {
            $raw = base64_decode(strtr($request['raw'], '-_', '+/'));
            $cabeceras = iconv_mime_decode_headers(substr($raw, 0, strpos($raw, "\r\n\r\n")), 0, 'UTF-8');

            return str_starts_with($cabeceras['Subject'], '[REQ-'.$r->id.'] Seguimiento de requerimiento: Constancia de estudios');
        });
    }
}
