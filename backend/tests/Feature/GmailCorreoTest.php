<?php

namespace Tests\Feature;

use App\Models\ConexionCorreo;
use App\Models\Legajo;
use App\Models\RequerimientoDocumento;
use App\Models\Usuario;
use App\Support\CorreoRequerimiento;
use App\Support\GmailCorreo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GmailCorreoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gmail.client_id' => 'cliente-ficticio', 'services.gmail.client_secret' => 'secreto-ficticio',
            'services.gmail.redirect_uri' => null, 'services.gmail.reply_to' => null, 'sanctum.stateful' => ['localhost:5173']]);
        $this->withHeader('Origin', 'http://localhost:5173');
        Http::preventStrayRequests();
    }

    private function conectar(): ConexionCorreo
    {
        $admin = $this->actuarComo();

        return ConexionCorreo::query()->create(['clave' => 'gmail_institucional', 'email' => 'institucion@gmail.com',
            'access_token' => 'access-ficticio', 'refresh_token' => 'refresh-ficticio', 'expira_en' => now()->addHour(),
            'habilitada' => true, 'conectado_por' => $admin->id]);
    }

    public function test_only_administrator_can_connect_gmail_and_missing_credentials_do_not_start_authorization(): void
    {
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson('/api/v1/correo/gmail')->assertForbidden();
        $this->postJson('/api/v1/correo/gmail/autorizar')->assertForbidden();
        $this->actuarComo();
        config(['services.gmail.client_id' => null, 'services.gmail.client_secret' => null]);
        $this->getJson('/api/v1/correo/gmail')->assertOk()->assertJsonPath('data.credenciales_configuradas', false);
        $this->postJson('/api/v1/correo/gmail/autorizar')->assertConflict();
        Http::assertNothingSent();
    }

    public function test_google_connection_uses_state_and_pkce_and_encrypts_tokens_without_exposing_them(): void
    {
        $this->actuarComo();
        $auth = $this->postJson('/api/v1/correo/gmail/autorizar')->assertOk()->json('url');
        parse_str(parse_url($auth, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertStringContainsString(GmailCorreo::ALCANCE, $query['scope']);
        $this->assertStringContainsString(GmailCorreo::LECTURA, $query['scope']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-privado', 'refresh_token' => 'refresh-privado', 'expires_in' => 3600, 'scope' => GmailCorreo::ALCANCE.' '.GmailCorreo::LECTURA]),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'institucion@gmail.com', 'email_verified' => true]),
        ]);
        $this->get('/api/v1/correo/gmail/callback?'.http_build_query(['state' => $query['state'], 'code' => 'codigo-ficticio']))->assertRedirect('http://localhost:5173/configuracion?gmail=conectado');
        $c = ConexionCorreo::sole();
        $this->assertSame('refresh-privado', $c->refresh_token);
        $this->assertNotSame('refresh-privado', DB::table('conexiones_correo')->value('refresh_token'));
        $this->assertArrayNotHasKey('refresh_token', $c->toArray());
        $this->getJson('/api/v1/correo/gmail')->assertOk()->assertJsonPath('data.conectado', true)->assertJsonPath('data.lectura_respuestas', true)->assertDontSee('refresh-privado');
    }

    public function test_wrong_or_expired_state_never_exchanges_a_google_code(): void
    {
        $this->actuarComo();
        $this->postJson('/api/v1/correo/gmail/autorizar')->assertOk();
        $this->getJson('/api/v1/correo/gmail/callback?state=incorrecto&code=ficticio')->assertForbidden();
        Http::assertNothingSent();
        $auth = $this->postJson('/api/v1/correo/gmail/autorizar')->json('url');
        parse_str(parse_url($auth, PHP_URL_QUERY), $query);
        $this->travel(11)->minutes();
        $this->getJson('/api/v1/correo/gmail/callback?'.http_build_query(['state' => $query['state'], 'code' => 'ficticio']))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_expired_token_is_refreshed_and_email_is_sent_to_the_current_personal_email_without_account_access(): void
    {
        $c = $this->conectar();
        $c->update(['expira_en' => now()->subMinute()]);
        $u = Usuario::factory()->create(['email_institucional' => 'anterior@gmail.com']);
        Legajo::factory()->create(['usuario_id' => $u->id, 'estado_legajo' => 'activo']);
        $r = RequerimientoDocumento::factory()->create(['usuario_id' => $u->id, 'estado' => 'abierto', 'cerrado_en' => null]);
        $n = CorreoRequerimiento::programar($r, 'Documentación pendiente', 'Presentar constancia');
        $u->update(['email_institucional' => 'actual@gmail.com']);
        Mail::shouldReceive('raw')->never();
        Mail::shouldReceive('send')->never();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-renovado', 'expires_in' => 3600]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'gmail-mensaje-123']),
        ]);
        $this->assertSame(1, CorreoRequerimiento::procesar([$n->id]));
        $this->assertSame(0, CorreoRequerimiento::procesar([$n->id]));
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/messages/send')) {
                return false;
            }
            $raw = base64_decode(strtr($request['raw'], '-_', '+/'));

            return str_contains($raw, 'To: actual@gmail.com') && str_contains($raw, 'From: institucion@gmail.com')
                && str_contains($raw, 'Reply-To: institucion@gmail.com') && $request->hasHeader('Authorization', 'Bearer access-renovado');
        });
        $this->assertSame('gmail-mensaje-123', $n->fresh()->correo_id_proveedor);
        $this->assertSame('actual@gmail.com', $n->fresh()->correo_destinatario);
        $this->assertSame([], $u->fresh()->permisos());
    }

    public function test_disconnect_removes_tokens_and_does_not_fall_back_to_smtp(): void
    {
        $c = $this->conectar();
        config(['mail.default' => 'smtp', 'mail.from.address' => 'sender@example.test', 'mail.reply_to.address' => 'reply@example.test']);
        $this->deleteJson('/api/v1/correo/gmail')->assertOk();
        $this->assertNull($c->fresh()->refresh_token);
        $this->assertFalse(CorreoRequerimiento::configurado());
        Http::assertNothingSent();
    }

    public function test_local_relay_accepts_only_server_signed_institutional_callbacks(): void
    {
        $this->getJson('/api/v1/correo/gmail/retorno-local?state=manipulado&code=ficticio')->assertForbidden();
        $state = Crypt::encryptString(json_encode(['nonce' => 'ficticio', 'callback' => 'http://uniq.localhost:5173/api/v1/correo/gmail/callback']));
        $query = http_build_query(['state' => $state, 'code' => 'codigo-ficticio']);
        $this->get('/api/v1/correo/gmail/retorno-local?'.$query)->assertRedirect('http://uniq.localhost:5173/api/v1/correo/gmail/callback?'.$query);
        Http::assertNothingSent();
    }

    public function test_adding_a_gmail_to_existing_personnel_requires_an_explicit_delivery(): void
    {
        $this->conectar();
        $u = Usuario::factory()->create(['email_institucional' => '']);
        Legajo::factory()->create(['usuario_id' => $u->id, 'estado_legajo' => 'activo']);
        $r = RequerimientoDocumento::factory()->create(['usuario_id' => $u->id, 'estado' => 'abierto', 'cerrado_en' => null]);
        $n = CorreoRequerimiento::programar($r, 'Requerimiento', 'Presentar documento');
        $this->assertSame('sin_correo', $n->correo_estado);
        $u->update(['email_institucional' => 'docente-nuevo@gmail.com']);
        $this->assertSame(0, CorreoRequerimiento::procesar());
        Http::assertNothingSent();
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'gmail-recuperado'])]);
        $this->assertSame(1, CorreoRequerimiento::procesar([$n->id]));
        $this->assertSame('docente-nuevo@gmail.com', $n->fresh()->correo_destinatario);
        $this->assertSame([], $u->fresh()->permisos());
    }

    public function test_callback_requires_administrative_session_even_if_the_state_is_known(): void
    {
        $this->get('/api/v1/correo/gmail/callback?state=conocido&code=ficticio')->assertForbidden();
        Http::assertNothingSent();
    }
}
