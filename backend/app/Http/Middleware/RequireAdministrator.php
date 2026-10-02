<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->activo && $request->user()->isAdministrator(), 403, 'Esta cuenta no tiene acceso administrativo.');

        return $next($request);
    }
}
