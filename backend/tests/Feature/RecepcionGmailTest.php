<?php

namespace Tests\Feature;

use App\Models\ConexionCorreo;
use App\Models\Legajo;
use App\Models\NotificacionUsuario;
use App\Models\RequerimientoDocumento;
use App\Models\Usuario;
use App\Support\CorreoRequerimiento;
use App\Support\GmailCorreo;
use App\Support\RecepcionGmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecepcionGmailTest extends TestCase
{
    use RefreshDatabase;

    private ConexionCorreo $conexion;

    private RequerimientoDocumento $requerimiento;

    private array $mensajes = [];

    private bool $fallar = false;

    private bool $paginar = false;

    private string $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Pages /Count 1 >>\nendobj\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('legajo.disco_documentos'));
        $admin = $this->actuarComo();
        config(['services.gmail.client_id' => 'ficticio', 'services.gmail.client_secret' => 'ficticio']);
        $this->conexion = ConexionCorreo::query()->create(['clave' => 'gmail_institucional', 'email' => 'institucion@gmail.com',
            'access_token' => 'ficticio', 'refresh_token' => 'ficticio', 'expira_en' => now()->addHour(),
            'habilitada' => true, 'conectado_por' => $admin->id, 'alcances' => [GmailCorreo::ALCANCE, GmailCorreo::LECTURA]]);
        $u = Usuario::factory()->create(['email_institucional' => 'docente@gmail.com']);
        Legajo::factory()->create(['usuario_id' => $u->id, 'estado_legajo' => 'activo']);
        $this->requerimiento = RequerimientoDocumento::factory()->create(['usuario_id' => $u->id, 'clase' => 'documentos',
            'estado' => 'abierto', 'cerrado_en' => null, 'seguimiento' => []]);
        $n = CorreoRequerimiento::programar($this->requerimiento, 'Requerimiento', 'Presentar documento');
        $n->update(['correo_estado' => 'enviado', 'correo_enviado_en' => now()->subHour(), 'correo_id_proveedor' => 'enviado-previamente']);
        Http::preventStrayRequests();
        Mail::shouldReceive('raw')->never();
        Mail::shouldReceive('send')->never();
        Http::fake(function ($request) {
            if ($request->method() !== 'GET') {
                $this->fail('Recibir respuestas no debe enviar correos ni modificar el buzón.');
            }
            if ($this->fallar) {
                return Http::response([], 503);
            }
            $ruta = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($ruta, '/attachments/adjunto')) {
                return Http::response(['data' => $this->codificar($this->pdf), 'size' => strlen($this->pdf)]);
            }
            if (preg_match('~/messages/([^/]+)$~', $ruta, $match)) {
                return Http::response($this->mensajes[$match[1]] ?? [], isset($this->mensajes[$match[1]]) ? 200 : 404);
            }
            $ids = array_keys($this->mensajes);
            if ($this->paginar) {
                $segunda = ($request['pageToken'] ?? null) === 'segunda';
                $ids = array_slice($ids, $segunda ? 1 : 0, 1);

                return Http::response(['messages' => array_map(fn ($id) => ['id' => $id], $ids), 'nextPageToken' => $segunda ? null : 'segunda']);
            }

            return Http::response(['messages' => array_map(fn ($id) => ['id' => $id], $ids)]);
        });
    }

    private function codificar(string $texto): string
    {
        return rtrim(strtr(base64_encode($texto), '+/', '-_'), '=');
    }

    private function mensaje(string $id = 'respuesta1', array $partes = [], ?string $asunto = null): array
    {
        return ['id' => $id, 'internalDate' => (string) now()->subMinute()->getTimestampMs(), 'labelIds' => ['INBOX'],
            'payload' => ['mimeType' => 'multipart/mixed', 'headers' => [
                ['name' => 'From', 'value' => 'Docente <docente@gmail.com>'],
                ['name' => 'Subject', 'value' => $asunto ?? 'Re: [REQ-'.$this->requerimiento->id.'] Requerimiento'],
                ['name' => 'Authentication-Results', 'value' => 'mx.google.com; dkim=pass; spf=pass; dmarc=pass header.from=gmail.com'],
            ], 'parts' => $partes ?: [
                ['mimeType' => 'text/plain', 'body' => ['data' => $this->codificar('Adjunto mi documento. Gracias.')]],
                ['mimeType' => 'application/pdf', 'filename' => 'constancia.pdf', 'body' => ['size' => strlen($this->pdf), 'attachmentId' => 'adjunto']],
            ]]];
    }

    public function test_imports_message_and_private_pdf_once_marks_responded_and_never_sends_mail(): void
    {
        $this->mensajes['respuesta1'] = $this->mensaje();
        $this->artisan('legajo:recibir-respuestas')->assertSuccessful();
        $this->assertSame(0, RecepcionGmail::sincronizar());
        $r = $this->requerimiento->fresh();
        $this->assertSame('en_revision', $r->estado);
        $this->assertCount(1, $r->seguimiento);
        $evento = $r->seguimiento[0];
        $this->assertSame('Adjunto mi documento. Gracias.', $evento['mensaje']);
        $this->assertSame('gmail', $evento['canal']);
        $this->assertSame(hash('sha256', $this->pdf), $evento['archivos'][0]['hash']);
        Storage::disk(config('legajo.disco_documentos'))->assertExists($evento['archivos'][0]['ruta']);
        $this->getJson('/api/v1/solicitudes-legajo')->assertOk()->assertJsonMissingPath('data.0.seguimiento.0.archivos.0.ruta')
            ->assertJsonMissingPath('data.0.seguimiento.0.archivos.0.disco')->assertJsonPath('recepcion_automatica', true);
        $this->get('/api/v1/solicitudes-legajo/'.$r->id.'/archivos/0/0?ver=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseCount('correos_recibidos', 1);
        $this->assertDatabaseCount('notificaciones_usuario', 1);
        $this->assertDatabaseCount('documentos_usuario', 0);
        $this->assertNotNull($this->conexion->fresh()->recepcion_revisada_en);
        Sanctum::actingAs($r->usuario);
        $this->get('/api/v1/solicitudes-legajo/'.$r->id.'/archivos/0/0')->assertForbidden();
    }

    public function test_old_send_only_connection_does_not_read_until_reauthorized(): void
    {
        $this->conexion->update(['alcances' => null]);
        $this->assertSame(0, RecepcionGmail::sincronizar());
        $this->getJson('/api/v1/correo/gmail')->assertOk()->assertJsonPath('data.lectura_respuestas', false);
        Http::assertNothingSent();
        $this->assertTrue(CorreoRequerimiento::configurado());
    }

    public function test_rejects_other_senders_spoofed_mail_sent_messages_and_invalid_references(): void
    {
        foreach (['otro', 'suplantado', 'enviado', 'sinreferencia', 'malformado'] as $id) {
            $mensaje = $this->mensaje($id);
            if ($id === 'otro') {
                $mensaje['payload']['headers'][0]['value'] = 'otro@gmail.com';
            } elseif ($id === 'suplantado') {
                $mensaje['payload']['headers'][2]['value'] = 'mx.google.com; dmarc=fail';
            } elseif ($id === 'enviado') {
                $mensaje['labelIds'] = ['SENT'];
            } elseif ($id === 'sinreferencia') {
                $mensaje['payload']['headers'][1]['value'] = 'Un correo sin referencia';
            } else {
                $mensaje['payload']['headers'][1]['value'] = '[REQ-'.str_repeat('a', 36).']';
            }
            $this->mensajes[$id] = $mensaje;
        }
        $this->assertSame(0, RecepcionGmail::sincronizar());
        $this->assertSame('abierto', $this->requerimiento->fresh()->estado);
        $this->assertDatabaseCount('correos_recibidos', 5);
        $this->assertSame(5, DB::table('correos_recibidos')->where('estado', 'ignorado')->count());
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_multiple_pdfs_and_message_only_responses_are_imported_without_approving_documents(): void
    {
        $mensaje = $this->mensaje();
        $mensaje['payload']['parts'][] = ['mimeType' => 'application/pdf', 'filename' => 'otro.pdf', 'body' => ['data' => $this->codificar($this->pdf)]];
        $this->mensajes['respuesta1'] = $mensaje;
        $this->assertSame(1, RecepcionGmail::sincronizar());
        $this->assertCount(2, $this->requerimiento->fresh()->seguimiento[0]['archivos']);
        $this->mensajes['respuesta2'] = $this->mensaje('respuesta2', [['mimeType' => 'text/plain', 'body' => ['data' => $this->codificar('Este requisito no corresponde.')]]]);
        $this->assertSame(1, RecepcionGmail::sincronizar());
        $this->assertCount(2, $this->requerimiento->fresh()->seguimiento);
        $this->assertSame('en_revision', $this->requerimiento->fresh()->estado);
        $this->assertDatabaseCount('documentos_usuario', 0);
    }

    public function test_invalid_oversize_and_unsupported_attachments_are_not_stored_but_response_is_visible(): void
    {
        $this->mensajes['respuesta1'] = $this->mensaje('respuesta1', [
            ['mimeType' => 'text/plain', 'body' => ['data' => $this->codificar('Mis archivos adjuntos')]],
            ['mimeType' => 'application/pdf', 'filename' => 'falso.pdf', 'body' => ['data' => $this->codificar('No es PDF')]],
            ['mimeType' => 'application/pdf', 'filename' => 'grande.pdf', 'body' => ['size' => 10000000, 'attachmentId' => 'no-descargar']],
            ['mimeType' => 'application/zip', 'filename' => 'archivo.zip', 'body' => ['data' => $this->codificar('zip')]],
        ]);
        $this->assertSame(1, RecepcionGmail::sincronizar());
        $evento = $this->requerimiento->fresh()->seguimiento[0];
        $this->assertCount(0, $evento['archivos']);
        $this->assertCount(3, $evento['avisos_adjuntos']);
        $this->assertSame('Mis archivos adjuntos', $evento['mensaje']);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_closed_requirements_and_unsent_requirements_do_not_accept_delivery(): void
    {
        $this->mensajes['respuesta1'] = $this->mensaje();
        NotificacionUsuario::query()->update(['correo_estado' => 'pendiente', 'correo_enviado_en' => null]);
        $this->assertSame(0, RecepcionGmail::sincronizar());
        NotificacionUsuario::query()->update(['correo_estado' => 'enviado', 'correo_enviado_en' => now()->subHour()]);
        $this->requerimiento->update(['estado' => 'aceptado', 'cerrado_en' => now()]);
        $this->mensajes['respuesta2'] = $this->mensaje('respuesta2');
        $this->assertSame(0, RecepcionGmail::sincronizar());
        $this->assertSame('aceptado', $this->requerimiento->fresh()->estado);
        $this->assertCount(0, $this->requerimiento->fresh()->seguimiento);
    }

    public function test_pagination_html_and_transient_failures_preserve_the_cursor_and_prevent_duplicates(): void
    {
        $this->paginar = true;
        $this->mensajes['respuesta1'] = $this->mensaje();
        $this->mensajes['respuesta2'] = $this->mensaje('respuesta2', [['mimeType' => 'text/html', 'body' => ['data' => $this->codificar('<p>Respuesta HTML</p><script>alert(1)</script>')]]]);
        $this->assertSame(2, RecepcionGmail::sincronizar());
        $this->assertSame('Respuesta HTML', $this->requerimiento->fresh()->seguimiento[1]['mensaje']);
        $revisada = $this->conexion->fresh()->recepcion_revisada_en;
        $this->fallar = true;
        $this->travel(5)->minutes();
        try {
            RecepcionGmail::sincronizar();
            $this->fail('Se esperaba un fallo de conexión.');
        } catch (\RuntimeException) {
            $this->assertNotNull($this->conexion->fresh()->recepcion_error);
        }
        $this->assertTrue($revisada->equalTo($this->conexion->fresh()->recepcion_revisada_en));
        $this->fallar = false;
        $this->assertSame(0, RecepcionGmail::sincronizar());
        $this->assertDatabaseCount('correos_recibidos', 2);
        $this->assertNull($this->conexion->fresh()->recepcion_error);
    }

    public function test_usual_reply_and_forward_prefixes_keep_the_requirement_reference(): void
    {
        $ref = '[REQ-'.$this->requerimiento->id.']';
        $asuntos = ['RE: RV: '.$ref.' Institución · Requerimiento de documentación', 'Fwd: Re: '.$ref.' Seguimiento de requerimiento',
            'Re: '.strtoupper($ref).' Requerimiento'];
        foreach ($asuntos as $i => $asunto) {
            $this->mensajes['prefijo'.$i] = $this->mensaje('prefijo'.$i, [['mimeType' => 'text/plain', 'body' => ['data' => $this->codificar('Respuesta '.$i)]]], $asunto);
        }
        $this->assertSame(3, RecepcionGmail::sincronizar());
        $this->assertSame(3, DB::table('correos_recibidos')->where('estado', 'importado')->where('requerimiento_id', $this->requerimiento->id)->count());
        $this->assertCount(3, $this->requerimiento->fresh()->seguimiento);
    }

    public function test_subject_with_two_different_references_is_not_assigned_to_either_requirement(): void
    {
        $otro = RequerimientoDocumento::factory()->create(['usuario_id' => $this->requerimiento->usuario_id, 'clase' => 'documentos',
            'estado' => 'abierto', 'cerrado_en' => null, 'seguimiento' => []]);
        CorreoRequerimiento::programar($otro, 'Requerimiento', 'Otro documento')
            ->update(['correo_estado' => 'enviado', 'correo_enviado_en' => now()->subHour()]);
        $ref = '[REQ-'.$this->requerimiento->id.']';
        $this->mensajes['dos'] = $this->mensaje('dos', [], 'Re: '.$ref.' y [REQ-'.$otro->id.'] Requerimientos');
        $this->mensajes['repetida'] = $this->mensaje('repetida', [['mimeType' => 'text/plain', 'body' => ['data' => $this->codificar('Misma referencia')]]], 'Re: '.$ref.' Fwd: '.$ref);
        $this->assertSame(1, RecepcionGmail::sincronizar());
        $this->assertDatabaseHas('correos_recibidos', ['mensaje_id' => 'dos', 'estado' => 'ignorado', 'requerimiento_id' => null]);
        $this->assertDatabaseHas('correos_recibidos', ['mensaje_id' => 'repetida', 'estado' => 'importado', 'requerimiento_id' => $this->requerimiento->id]);
        $this->assertSame([], $otro->fresh()->seguimiento);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }
}
