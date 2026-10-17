<?php

namespace App\Http\Middleware;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\TipoDocumento;
use App\Support\AccesoLegajo;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AccesoSecciones
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/acceso-secciones*', 'api/v1/incorporaciones*')) {
            return $next($request);
        }
        $desactivadas = collect(AccesoLegajo::secciones())->where('activa', false)->pluck('id')->all();
        $path = $request->path();
        if (preg_match('#/seccion-(\d+)(?:/|$)#', $path, $matches)) {
            $this->permitir((int) $matches[1], $desactivadas);
        }
        foreach (['seccion_legajo_id', 'seccion_id'] as $campo) {
            if ($request->filled($campo)) {
                $this->permitir((int) $request->input($campo), $desactivadas);
            }
        }
        if ($request->is('api/v1/expedientes/*') && ! $request->isMethod('GET') && ! $request->route('section') && ! $request->route('documento') && (str_ends_with($path, '/foto') || count(explode('/', $path)) === 4)) {
            $this->permitir(1, $desactivadas);
        }
        $controller = class_basename($request->route()->getControllerClass() ?? '');
        if (preg_match('/^Seccion(\d+)Controller$/', $controller, $matches)) {
            $this->permitir((int) $matches[1], $desactivadas);
        }
        foreach (AccesoLegajo::MODELOS as $seccion => $modelos) {
            foreach ($modelos as $modelo) {
                if (in_array($controller, [$modelo.'Controller', $modelo.'PersonalController'], true)) {
                    $this->permitir($seccion, $desactivadas);
                }
            }
        }
        if ($request->filled('tipo_documento_id')) {
            $tipo = TipoDocumento::query()->find($request->input('tipo_documento_id'));
            if ($tipo) {
                $this->permitir($tipo->seccion_legajo_id, $desactivadas);
            }
        }
        // Los ámbitos se limitan a esta petición y cubren también listados genéricos, conteos y descargas.
        $scopes = Model::getAllGlobalScopes();
        try {
            foreach (AccesoLegajo::MODELOS as $seccion => $modelos) {
                foreach ($modelos as $modelo) {
                    $class = 'App\\Models\\'.$modelo;
                    if (in_array($seccion, $desactivadas, true)) {
                        $class::addGlobalScope('acceso_seccion', fn (Builder $q) => $q->whereRaw('1 = 0'));
                    }
                }
            }
            TipoDocumento::addGlobalScope('acceso_seccion', fn (Builder $q) => $q->whereNotIn('seccion_legajo_id', $desactivadas));
            DocumentoUsuario::addGlobalScope('acceso_seccion', fn (Builder $q) => $q->whereNotIn('documentos_usuario.seccion_legajo_id', $desactivadas));
            ArchivoDocumento::addGlobalScope('acceso_seccion', fn (Builder $q) => $q->whereNotIn('id',
                DB::table('documentos_usuario')->whereNotNull('archivo_id')->whereIn('seccion_legajo_id', $desactivadas)->select('archivo_id')));

            return $next($request);
        } finally {
            Model::setAllGlobalScopes($scopes);
        }
    }

    private function permitir(int $seccion, array $desactivadas): void
    {
        abort_if(in_array($seccion, $desactivadas, true), 403, 'Esta sección del legajo está desactivada.');
    }
}
