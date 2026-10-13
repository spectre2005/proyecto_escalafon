<?php

namespace App\Http\Controllers;

use App\Models\Plataforma\Institucion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Caddy (HTTPS por demanda) pregunta aquí antes de pedir un certificado: GET /interno/tls?domain=x. Responde 200 solo
 * para el dominio de la plataforma y los subdominios de instituciones registradas; así nadie puede hacerle pedir
 * certificados para nombres inventados. Caddy no publica /interno: solo lo consulta por su red interna.
 */
class PermisoCertificadoController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $host = strtolower(trim((string) $request->query('domain')));
        $subdominios = config('plataforma.modo') === 'subdominio';
        // Con subdominios, el dominio de la plataforma; con una sola institución, el de APP_URL.
        $dominio = strtolower((string) ($subdominios ? config('plataforma.dominio') : parse_url((string) config('app.url'), PHP_URL_HOST)));
        $slug = $subdominios && str_ends_with($host, '.'.$dominio) ? substr($host, 0, -strlen('.'.$dominio)) : null;
        $permitido = $host !== '' && ($host === $dominio
            || ($slug !== null && preg_match(Institucion::PATRON_SLUG, $slug) && Institucion::query()->where('slug', $slug)->exists()));

        return response($permitido ? 'ok' : 'no', $permitido ? 200 : 404);
    }
}
