<?php

use App\Http\Middleware\LegajoSoloLectura;
use App\Http\Middleware\RequireAdministrator;
use App\Http\Middleware\ResolverInstitucion;
use App\Http\Middleware\SesionPlataforma;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Panel de la plataforma SaaS: rutas propias bajo api/plataforma (routes/plataforma.php), con su cookie de sesión.
        then: fn () => Route::middleware([SesionPlataforma::class, 'api'])->prefix('api/plataforma')->group(base_path('routes/plataforma.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Antes que todo (también de la sesión): con PLATAFORMA_MODO=subdominio cambia a la base de la institución.
        $middleware->prepend(ResolverInstitucion::class);
        $middleware->statefulApi();
        $middleware->appendToPriorityList(Authorize::class, LegajoSoloLectura::class);
        // Alias temporal: las rutas ya usan can:<permiso>.
        $middleware->alias(['administrador' => RequireAdministrator::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
