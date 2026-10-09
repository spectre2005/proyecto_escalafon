<?php

namespace App\Http\Middleware;

use App\Models\Afiliacion;
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
        abort_if(str_contains($path, '/controversias'), 403, 'En relaciones laborales solo está habilitada la afiliación de sindicato.');
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
                abort_if($tipo->seccion_legajo_id === 9 && $tipo->codigo !== 'S09_C_AFILIACION_SINDICATO', 403, 'Solo está habilitada la afiliación de sindicato.');
            }
        }
        // Los ámbitos se limitan a esta petición y cubren también listados genéricos, conteos y descargas.
        $scopes = Model::getAllGlobalScopes();
        try {
            foreach (AccesoLegajo::MODELOS as $seccion => $modelos) {
                foreach ($modelos as $modelo) {
                    $class = 'App\\Models\\'.$modelo;
                    if (in_array($seccion, $desactivadas, true) || $modelo === 'Controversia') {
                        $class::addGlobalScope('acceso_seccion', fn (Builder $q) => $q->whereRaw('1 = 0'));
                    }
                }
            }
            Afiliacion::addGlobalScope('solo_sindicato', fn (Builder $q) => $q->where('tipo', 'sindicato'));
            TipoDocumento::addGlobalScope('acceso_seccion', fn (Builder $q) => $q->whereNotIn('seccion_legajo_id', $desactivadas)
                ->where(fn (Builder $q) => $q->where('seccion_legajo_id', '!=', 9)->orWhere('codigo', 'S09_C_AFILIACION_SINDICATO')));
            DocumentoUsuario::addGlobalScope('acceso_seccion', fn (Builder $q) => $q->whereNotIn('documentos_usuario.seccion_legajo_id', $desactivadas)
                ->where(fn (Builder $q) => $q->where('documentos_usuario.seccion_legajo_id', '!=', 9)->orWhereHas('tipoDocumento', fn (Builder $t) => $t->where('codigo', 'S09_C_AFILIACION_SINDICATO'))));
            ArchivoDocumento::addGlobalScope('acceso_seccion', fn (Builder $q) => $q->whereNotIn('id',
                DB::table('documentos_usuario')->whereNotNull('archivo_id')
                    ->where(fn ($d) => $d->whereIn('seccion_legajo_id', $desactivadas)->orWhere(fn ($d) => $d->where('seccion_legajo_id', 9)
                        ->whereNotIn('tipo_documento_id', DB::table('tipos_documento')->where('codigo', 'S09_C_AFILIACION_SINDICATO')->select('id'))))->select('archivo_id')));

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
