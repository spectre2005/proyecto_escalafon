<?php

namespace App\Http\Middleware;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\ConexionInstitucion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Con PLATAFORMA_MODO=subdominio, identifica la institución por el subdominio ({slug}.{PLATAFORMA_DOMINIO}) y atiende la
 * petición con su base, su almacenamiento y sus sesiones (ConexionInstitucion). Es global y va primero, antes de que se
 * abra la sesión. Solo opera una institución activa: la registrada responde como inexistente y la suspendida, 403.
 * El panel (api/plataforma) solo responde en el dominio base, sin subdominio.
 * Con PLATAFORMA_MODO=unica (por defecto) no hace nada: una sola institución con la base del .env.
 */
class ResolverInstitucion
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('plataforma.modo') !== 'subdominio' || $request->is('up')) {
            return $next($request);
        }
        $dominio = strtolower((string) config('plataforma.dominio'));
        $host = strtolower($request->getHost());
        if ($request->is('api/plataforma', 'api/plataforma/*')) {
            abort_unless($host === $dominio, 404);

            return $next($request);
        }
        $slug = str_ends_with($host, '.'.$dominio) ? substr($host, 0, -strlen('.'.$dominio)) : '';
        $institucion = $slug !== '' && ! in_array($slug, Institucion::SLUGS_RESERVADOS, true)
            ? Institucion::query()->where('slug', $slug)->first() : null;
        if (! $institucion || $institucion->estado === Institucion::REGISTRADA || ! $institucion->aprovisionada()) {
            return self::rechazo(404, 'institucion_no_encontrada', 'No existe una institución activa en esta dirección.');
        }
        if ($institucion->estado === Institucion::SUSPENDIDA) {
            return self::rechazo(403, 'institucion_suspendida', 'El acceso de esta institución está suspendido. Comunícate con el soporte de la plataforma.');
        }

        return ConexionInstitucion::usar($institucion, fn (): Response => $next($request));
    }

    /** JSON con «codigo» para que el frontend muestre el motivo en lugar de un error genérico. */
    private static function rechazo(int $estado, string $codigo, string $mensaje): Response
    {
        return response()->json(['message' => $mensaje, 'codigo' => $codigo], $estado);
    }
}
