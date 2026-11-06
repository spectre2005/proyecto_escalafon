<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Legajos\DocumentoLegajoController;
use App\Http\Controllers\Controller;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\ConfiguracionFuncionalidades;
use App\Support\Documentos\ArchivoPdf;
use App\Support\Documentos\EscanerSecciones;
use App\Support\Parametros;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class EscanerSeccionesController extends Controller
{
    public function __invoke(Request $request, EscanerSecciones $escaner): JsonResponse
    {
        $datos = $request->validate([
            'usuario_id' => 'required|uuid|exists:usuarios,id',
            'tipo_documento_id' => 'required|uuid|exists:tipos_documento,id',
            'campos' => 'required|array|min:1|max:22',
            'campos.*' => ['required', 'string', 'distinct', Rule::in(EscanerSecciones::CAMPOS)],
            'pdf' => ['required', 'file', 'max:'.Parametros::entero('tamano_maximo_pdf_kb')],
        ]);
        $tipo = TipoDocumento::query()->findOrFail($datos['tipo_documento_id']);
        $seccion = (int) $tipo->seccion_legajo_id;
        abort_unless($tipo->activo && $seccion >= 1 && $seccion <= 11, 422, 'Selecciona un tipo de documento activo.');
        abort_unless(Legajo::query()->where('usuario_id', $datos['usuario_id'])->where('estado_legajo', 'activo')->exists(), 409, 'Abre un legajo activo antes de escanear.');
        Usuario::query()->findOrFail($datos['usuario_id']);
        $restriccion = DocumentoLegajoController::restriccion($request->user(), $seccion, $tipo);
        abort_if($restriccion !== null, 403, (string) $restriccion);
        abort_if($seccion === 7 && ! $request->user()->can('seccion07.ver'), 403);
        abort_if(ConfiguracionFuncionalidades::inactivaPorTipo($tipo->id) !== null, 409, 'Esta funcionalidad está desactivada.');
        abort_unless(ArchivoPdf::esPdf($request->file('pdf')), 422, 'Adjunta un PDF válido.');
        $bloqueo = Cache::lock('escaner-documentos-local', 280);
        abort_unless($bloqueo->get(), 429, 'Hay otra lectura en curso. Espera a que termine y vuelve a intentarlo.');
        try {
            $texto = $escaner->leer($request->file('pdf'));
            $campos = $escaner->interpretar($texto, $tipo->nombre, $datos['campos']);

            return response()->json(['data' => ['campos' => $campos, 'aviso' => 'Se leyeron hasta 3 páginas. Revisa los datos contra el PDF antes de guardar. La lectura no acredita autenticidad.']])
                ->header('Cache-Control', 'private, no-store');
        } catch (ConnectionException) {
            abort(503, 'No se pudo conectar con Ollama. Inicia el servicio y verifica el modelo local.');
        } finally {
            $bloqueo->release();
        }
    }
}
