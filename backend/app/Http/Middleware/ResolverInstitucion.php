<?php

namespace App\Http\Middleware;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\ConexionInstitucion;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Con PLATAFORMA_MODO=subdominio, identifica la institución por el subdominio ({slug}.{PLATAFORMA_DOMINIO}) y atiende la
 * petición con su base, su almacenamiento y sus sesiones (ConexionInstitucion). Es global y va primero, antes de que se
 * abra la sesión, y se evalúa en cada petición: una sesión ya abierta deja de servir en cuanto cambia el estado.
 * Lista blanca: solo opera un estado de Institucion::ESTADOS_OPERATIVOS (activa) con su tiempo de uso al día. La
 * registrada responde como inexistente; la suspendida, la vencida (pasados sus días de gracia) y cualquier otro estado
 * (futuro o no reconocido), 403.
 * El panel (api/plataforma) solo responde en el dominio base, sin subdominio.
 * Con PLATAFORMA_MODO=unica (por defecto) no hace nada: una sola institución con la base del .env, sin identificarla;
 * ningún estado del panel bloquea el acceso en ese modo.
 */
class ResolverInstitucion
{
    public function handle(Request $request, Closure $next): Response
    {
        // /up (salud) e /interno/tls (permiso de certificados de Caddy) no son de ninguna institución.
        if (config('plataforma.modo') !== 'subdominio' || $request->is('up', 'interno/tls')) {
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
        if (! $institucion->estadoOperativo()) {
            return self::rechazo(403, 'institucion_no_disponible', 'Esta institución no está disponible. Comunícate con el soporte de la plataforma.');
        }
        $servicio = $institucion->servicio();
        if ($servicio['estado'] === 'vencido') {
            $fecha = $servicio['vence_el'] ? ' el '.Carbon::parse($servicio['vence_el'])->format('d/m/Y') : '';

            return self::rechazo(403, 'institucion_vencida', "El tiempo de uso de esta institución venció{$fecha}. Comunícate con el soporte de la plataforma para renovarlo.");
        }

        return ConexionInstitucion::usar($institucion, fn (): Response => $next($request));
    }

    /** JSON con «codigo» para que el frontend muestre el motivo en lugar de un error genérico. */
    private static function rechazo(int $estado, string $codigo, string $mensaje): Response
    {
        return response()->json(['message' => $mensaje, 'codigo' => $codigo], $estado);
    }
}
