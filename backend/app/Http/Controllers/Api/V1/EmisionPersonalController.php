<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Legajo;
use App\Models\Usuario;
use App\Support\Auditoria;
use App\Support\EmisionesPersonales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Informe escalafonario y constancias de una persona con legajo (datos para el PDF que arma el frontend). Mismos permisos
 * que los formatos de la directiva (sistema.administrar y legajo.ver, en la ruta). Cada emisión queda en la auditoría
 * (reporte.exportado) sin datos personales: solo el documento y si se pudo emitir.
 */
class EmisionPersonalController extends Controller
{
    public function show(Request $request, string $usuario, string $documento): JsonResponse
    {
        $persona = Usuario::query()->findOrFail($usuario);
        $legajo = Legajo::paraConsulta($persona->id);
        abort_unless($legajo, 409, 'La persona no tiene legajo: no se puede emitir este documento.');
        $datos = EmisionesPersonales::emitir($request->user(), $persona, $legajo, $documento);
        Auditoria::registrar($request, 'reporte.exportado', 'reportes', (string) Str::uuid(), $persona->id, null,
            ['formato' => $documento, 'emitible' => $datos['emitible'] ?? true]);

        return response()->json(['data' => $datos])->header('Cache-Control', 'private, no-store');
    }
}
