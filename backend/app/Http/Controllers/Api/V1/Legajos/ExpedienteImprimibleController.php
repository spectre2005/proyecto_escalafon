<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\Legajo;
use App\Models\Usuario;
use App\Support\Documentos\ExpedienteImprimible;
use App\Support\Documentos\ZipExpediente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Expediente imprimible (secciones 01 a 04): vista previa (fase 1, solo lectura) y descarga del ZIP de originales con su
 * índice (fase 2). Ambas con legajo.ver (ruta) y la regla de acceso del visor por sección y tipo.
 */
class ExpedienteImprimibleController extends Controller
{
    public function show(Request $request, string $usuario): JsonResponse
    {
        // Orden por folio de cada sección: falta confirmar con la ORH el sentido físico de la carpeta (num. 9.3).
        $orden = $request->validate(['orden' => 'sometimes|in:asc,desc'])['orden'] ?? 'asc';
        $persona = Usuario::query()->findOrFail($usuario);
        $legajo = Legajo::paraConsulta($persona->id);
        abort_unless($legajo, 409, 'La persona no tiene legajo: no hay documentos que ordenar.');

        return response()->json(['data' => ExpedienteImprimible::vistaPrevia($request->user(), $persona, $legajo, $orden)])
            ->header('Cache-Control', 'private, no-store');
    }

    /** ZIP de los originales con su índice HTML (botón «Descargar ZIP» del legajo). Se arma al pedirlo y se borra al enviarlo. */
    public function descargar(Request $request, string $usuario): BinaryFileResponse
    {
        // Grupos opcionales: solo los que el usuario marque; sin ninguno, secciones 01 a 04.
        $incluir = $request->validate(['incluir' => 'sometimes|array', 'incluir.*' => ['string', Rule::in(array_keys(ExpedienteImprimible::OPCIONALES))]])['incluir'] ?? [];
        $persona = Usuario::query()->findOrFail($usuario);
        $legajo = Legajo::paraConsulta($persona->id);
        abort_unless($legajo, 409, 'La persona no tiene legajo: no hay documentos que descargar.');
        $zip = ZipExpediente::generar($request, $persona, $legajo, $incluir);

        return response()->download($zip['ruta'], $zip['nombre'], ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store'])
            ->deleteFileAfterSend();
    }
}
