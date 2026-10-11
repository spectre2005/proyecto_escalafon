<?php

namespace App\Http\Middleware;

use App\Support\ConfiguracionFuncionalidades;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Configuración de funcionalidades: una funcionalidad inactiva no admite operaciones nuevas (creación ni modificación).
 *
 * - Por ruta: las declaradas en rutas_escritura del catálogo, incluido el CRUD genérico de las tablas de sección.
 * - Por tipo de documento (tipo_documento_id): solo en las rutas de carga y edición directa del legajo
 *   (api/v1/expedientes/{usuario}/...). El CRUD genérico de administración que también recibe un tipo de documento
 *   (requerimientos-documentos, compromiso-documentos, referencias-legajo-pasivo) no muta el legajo y queda fuera.
 *
 * La lectura y la descarga no se tocan. Anular, recepcionar y revisar no están en rutas_escritura (pendiente U8).
 * Ocultarla en el frontend no basta.
 */
class FuncionalidadActiva
{
    /** Prefijo de las rutas de carga y edición directa del legajo, donde se revisa el tipo de documento. */
    private const RUTAS_LEGAJO = 'api/v1/expedientes/{usuario}/';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ruta = $request->route();
        if ($request->isMethodSafe() || ! $ruta instanceof Route) {
            return $next($request);
        }
        $nombre = ConfiguracionFuncionalidades::inactivaPorRuta($ruta, $request->method())
            ?? (str_starts_with($ruta->uri(), self::RUTAS_LEGAJO) ? ConfiguracionFuncionalidades::inactivaPorTipo($request->input('tipo_documento_id')) : null);
        if ($nombre !== null) {
            return response()->json(['message' => "«{$nombre}» está desactivada en la configuración de la institución."], 409);
        }

        return $next($request);
    }
}
