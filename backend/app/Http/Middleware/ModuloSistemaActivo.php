<?php

namespace App\Http\Middleware;

use App\Support\ModulosSistema;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ModuloSistemaActivo
{
    public function handle(Request $request, Closure $next, string $codigo): Response
    {
        if (! ModulosSistema::activa($codigo)) {
            return response()->json(['message' => 'Este módulo está desactivado para tu institución.', 'codigo' => 'modulo_desactivado'], 403);
        }

        return $next($request);
    }
}
