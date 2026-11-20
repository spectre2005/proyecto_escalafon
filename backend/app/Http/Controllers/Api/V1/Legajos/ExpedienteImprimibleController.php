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
 * Expediente imprimible: vista previa (solo lectura) y descarga del ZIP de originales con su índice, de los grupos que elige
 * quien descarga. Por defecto, el legajo activo (o el último); con ?legajo=, cualquier legajo de la persona, también uno
 * pasivo. Ambas con legajo.ver (ruta) y la regla de acceso del visor por sección y tipo.
 */
class ExpedienteImprimibleController extends Controller
{
    public function show(Request $request, string $usuario): JsonResponse
    {
        // Orden por folio de cada sección: falta confirmar con la ORH el sentido físico de la carpeta (num. 9.3).
        $datos = $request->validate(['orden' => 'sometimes|in:asc,desc', 'legajo' => 'sometimes|uuid', ...self::reglasGrupos()]);
        $persona = Usuario::query()->findOrFail($usuario);
        $legajo = $this->legajo($persona, $datos['legajo'] ?? null, 'La persona no tiene legajo: no hay documentos que ordenar.');

        return response()->json(['data' => ExpedienteImprimible::vistaPrevia($request->user(), $persona, $legajo, $datos['orden'] ?? 'asc', $datos['incluir'] ?? [])])
            ->header('Cache-Control', 'private, no-store');
    }

    /** ZIP de los originales con su índice HTML (botón «Descargar ZIP» del legajo). Se arma al pedirlo y se borra al enviarlo. */
    public function descargar(Request $request, string $usuario): BinaryFileResponse
    {
        // Grupos que marca quien descarga (al menos uno si se envía la lista); sin lista, las secciones 01 a 04.
        $datos = $request->validate(['legajo' => 'sometimes|uuid', ...self::reglasGrupos()]);
        $persona = Usuario::query()->findOrFail($usuario);
        $legajo = $this->legajo($persona, $datos['legajo'] ?? null, 'La persona no tiene legajo: no hay documentos que descargar.');
        $zip = ZipExpediente::generar($request, $persona, $legajo, $datos['incluir'] ?? []);

        return response()->download($zip['ruta'], $zip['nombre'], ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store'])
            ->deleteFileAfterSend();
    }

    /** @return array<string, mixed> */
    private static function reglasGrupos(): array
    {
        return ['incluir' => 'sometimes|array|min:1', 'incluir.*' => ['string', 'distinct', Rule::in(array_keys(ExpedienteImprimible::GRUPOS))]];
    }

    /** El legajo pedido (debe ser de la persona, activo o pasivo) o, sin pedirlo, el activo o el último. */
    private function legajo(Usuario $persona, ?string $id, string $sinLegajo): Legajo
    {
        if ($id !== null) {
            return Legajo::query()->where('usuario_id', $persona->id)->findOrFail($id);
        }
        $legajo = Legajo::paraConsulta($persona->id);
        abort_unless($legajo, 409, $sinLegajo);

        return $legajo;
    }
}
