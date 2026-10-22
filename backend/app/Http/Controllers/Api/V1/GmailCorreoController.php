<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\GmailCorreo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class GmailCorreoController extends Controller
{
    private function redirectUri(Request $request): string
    {
        $local = app()->environment(['local', 'testing']) && str_ends_with($request->getHost(), '.localhost');
        $porDefecto = $local
            ? $request->getScheme().'://localhost:'.$request->getPort().'/api/v1/correo/gmail/retorno-local'
            : route('correo.gmail.callback');
        $uri = config('services.gmail.redirect_uri') ?: $porDefecto;
        $esRetornoLocal = $local && parse_url($uri, PHP_URL_HOST) === 'localhost' && parse_url($uri, PHP_URL_PATH) === '/api/v1/correo/gmail/retorno-local';
        abort_unless(in_array(parse_url($uri, PHP_URL_SCHEME), ['https', 'http'], true) && (parse_url($uri, PHP_URL_HOST) === $request->getHost() || $esRetornoLocal), 422,
            'La URL de retorno de Gmail debe pertenecer a esta institución o al retorno local de desarrollo.');

        return $uri;
    }

    public function estado(Request $request): JsonResponse
    {
        $c = GmailCorreo::conexion();

        return response()->json(['data' => ['credenciales_configuradas' => GmailCorreo::credencialesConfiguradas(), 'conectado' => GmailCorreo::listo(),
            'email' => $c?->email, 'redirect_uri' => $this->redirectUri($request), 'lectura_respuestas' => GmailCorreo::recepcionLista(),
            'recepcion_revisada_en' => $c?->recepcion_revisada_en, 'recepcion_error' => $c?->recepcion_error]])->header('Cache-Control', 'private, no-store');
    }

    public function autorizar(Request $request): JsonResponse
    {
        abort_unless(GmailCorreo::credencialesConfiguradas(), 409, 'Primero configura GMAIL_CLIENT_ID y GMAIL_CLIENT_SECRET en el servidor.');
        $state = Crypt::encryptString(json_encode(['nonce' => Str::random(64), 'callback' => route('correo.gmail.callback')]));
        $verifier = Str::random(80);
        $redirect = $this->redirectUri($request);
        $origin = $request->header('Origin');
        $return = $origin && parse_url($origin, PHP_URL_HOST) === $request->getHost() && in_array(parse_url($origin, PHP_URL_SCHEME), ['http', 'https'], true)
            ? $origin : $request->getSchemeAndHttpHost();
        $request->session()->put('gmail_oauth', ['state' => $state, 'verifier' => $verifier, 'redirect' => $redirect,
            'autor' => $request->user()->id, 'base' => DB::connection()->getDatabaseName(), 'expira' => now()->addMinutes(10)->timestamp, 'retorno' => $return.'/configuracion']);
        $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.gmail.client_id'), 'redirect_uri' => $redirect, 'response_type' => 'code',
            'scope' => 'openid email '.GmailCorreo::ALCANCE.' '.GmailCorreo::LECTURA, 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]);

        return response()->json(['url' => $url]);
    }

    /** Retorno local común para Google; devuelve el navegador al subdominio y su sesión institucional. */
    public function retornoLocal(Request $request): RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']) && $request->getHost() === 'localhost', 404);
        $data = $request->validate(['state' => 'required|string|max:4096', 'code' => 'nullable|string|max:4096', 'error' => 'nullable|string|max:200']);
        try {
            $flow = json_decode(Crypt::decryptString($data['state']), true, flags: JSON_THROW_ON_ERROR);
            $target = $flow['callback'] ?? '';
        } catch (Throwable) {
            abort(403, 'Retorno de Google no válido.');
        }
        abort_unless(is_string($target) && str_ends_with((string) parse_url($target, PHP_URL_HOST), '.localhost')
            && in_array(parse_url($target, PHP_URL_SCHEME), ['http', 'https'], true)
            && parse_url($target, PHP_URL_PATH) === '/api/v1/correo/gmail/callback'
            && ! parse_url($target, PHP_URL_QUERY) && ! parse_url($target, PHP_URL_FRAGMENT), 403);

        return redirect()->away($target.'?'.http_build_query($data));
    }

    public function callback(Request $request): RedirectResponse
    {
        $flow = $request->session()->pull('gmail_oauth');
        abort_unless(is_array($flow) && is_string($request->query('state')) && hash_equals($flow['state'], $request->query('state'))
            && $flow['autor'] === $request->user()->id && $flow['base'] === DB::connection()->getDatabaseName() && $flow['expira'] >= now()->timestamp, 403, 'La autorización de Gmail venció o no corresponde a esta sesión.');
        if ($request->query('error')) {
            return redirect()->away($flow['retorno'].'?gmail=cancelado');
        }
        $data = $request->validate(['code' => 'required|string|max:4096']);
        try {
            GmailCorreo::conectar($data['code'], $flow['redirect'], $flow['verifier'], $request->user()->id);
        } catch (Throwable) {
            return redirect()->away($flow['retorno'].'?gmail=error');
        }

        return redirect()->away($flow['retorno'].'?gmail=conectado');
    }

    public function desconectar(): JsonResponse
    {
        GmailCorreo::conexion()?->update(['habilitada' => false, 'access_token' => null, 'refresh_token' => null, 'expira_en' => null]);

        return response()->json(['message' => 'Gmail desconectado. El envío de nuevos correos queda detenido.']);
    }
}
